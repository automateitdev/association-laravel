<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Document;
use App\Models\Tenant\Member;
use App\Models\Tenant\Nominee;
use App\Models\User;
use App\Services\DocumentService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Member and nominee identity documents (parity P-10).
 *
 * BOTH AUDIENCES FROM ONE PLACE, as with payment documents, because the
 * authorisation rule is the same shape for each: a member reaches their OWN
 * documents and their own nominees'; staff reach anybody's if they may view
 * members. Splitting it would mean two places to get that wrong, and this is
 * the endpoint that serves photographs of people's identity cards.
 *
 * A MEMBER SUBMITS, AN OFFICER DECIDES (FR-MEM-8). A member's upload does not
 * become the document the association holds - it waits as `pending` beside the
 * live one, and staff approve or reject it. The office keeps working from the
 * NID it already has in the meantime, rather than the register briefly holding
 * whatever was last uploaded by whoever had the phone.
 *
 * Staff filing a document at the counter skips the queue, because there is
 * nobody left to review it: the person who would approve it is the one who
 * uploaded it.
 */
class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    // ------------------------------------------------------------ member's own

    public function meIndex(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->documents->list($this->self($request))]);
    }

    public function meShow(Request $request, string $slot): StreamedResponse
    {
        return $this->stream($this->self($request), $slot);
    }

    /**
     * A member submits a document for review.
     *
     * Nothing the association holds changes here. What comes back is the same
     * slot list as any other read, with this slot now marked pending - so the
     * screen shows the member what is waiting rather than implying it is done.
     */
    public function meSubmit(Request $request): JsonResponse
    {
        $member = $this->self($request);

        $validated = $request->validate([
            'slot' => ['required', 'string', 'max:40'],
            'file' => ['required', 'file'],
        ]);

        try {
            $this->documents->submit($member, $validated['slot'], $request->file('file'));
        } catch (DomainException $e) {
            throw new ApiException('DOCUMENT_REJECTED', $e->getMessage(), 422);
        }

        return response()->json(['data' => $this->documents->list($member)], 201);
    }

    /**
     * The member's own pending upload, so they can see what they sent.
     *
     * Separate from meShow because they are different documents: one is what
     * the association holds, the other is what the member is asking it to hold.
     */
    public function mePendingShow(Request $request, string $slot): StreamedResponse
    {
        return $this->stream($this->self($request), $slot, Document::STATUS_PENDING);
    }

    // ------------------------------------------------------------------- staff

    public function index(Request $request, int $member): JsonResponse
    {
        return response()->json([
            'data' => $this->documents->list($this->member($request, $member)),
        ]);
    }

    public function store(Request $request, int $member): JsonResponse
    {
        return $this->upload($request, $this->member($request, $member, write: true));
    }

    public function show(Request $request, int $member, string $slot): StreamedResponse
    {
        return $this->stream($this->member($request, $member), $slot);
    }

    public function destroy(Request $request, int $member, string $slot): JsonResponse
    {
        return $this->remove($this->member($request, $member, write: true), $slot);
    }

    // ---------------------------------------------------------------- nominees

    public function nomineeIndex(Request $request, int $nominee): JsonResponse
    {
        return response()->json([
            'data' => $this->documents->list($this->nominee($request, $nominee)),
        ]);
    }

    public function nomineeStore(Request $request, int $nominee): JsonResponse
    {
        return $this->upload($request, $this->nominee($request, $nominee, write: true));
    }

    public function nomineeShow(Request $request, int $nominee, string $slot): StreamedResponse
    {
        return $this->stream($this->nominee($request, $nominee), $slot);
    }

    public function nomineeDestroy(Request $request, int $nominee, string $slot): JsonResponse
    {
        return $this->remove($this->nominee($request, $nominee, write: true), $slot);
    }

    // ------------------------------------------------------------ the review queue

    /**
     * Everything waiting on a decision, across every member.
     *
     * Flat rather than grouped by member: an officer works a queue oldest
     * first, and a member with three documents waiting is three decisions, not
     * one.
     */
    public function reviewIndex(): JsonResponse
    {
        $slots = DocumentService::MEMBER_SLOTS + DocumentService::NOMINEE_SLOTS;

        return response()->json([
            'data' => $this->documents->queue()->map(fn (Document $document) => [
                'id' => $document->id,
                'slot' => $document->slot,
                'label' => $slots[$document->slot] ?? $document->slot,

                // Who it belongs to, and whether that is a member or one of
                // their nominees - the officer needs both to judge it.
                'owner_type' => $document->documentable instanceof Nominee ? 'nominee' : 'member',
                'owner_id' => $document->documentable_id,
                'owner_name' => $document->documentable?->name,

                'original_name' => $document->original_name,
                'mime' => $document->mime,
                'size' => $document->size,
                'submitted_at' => $document->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /** The pending file itself, so an officer can look before deciding. */
    public function reviewShow(int $document): StreamedResponse
    {
        $pending = $this->pending($document);

        if (! $pending->hasFile()) {
            throw ApiException::notFound('Document');
        }

        return Storage::disk($pending->disk)->response(
            $pending->path,
            $pending->original_name,
            ['Content-Type' => $pending->mime, 'Cache-Control' => 'private, no-store'],
        );
    }

    /**
     * Approve or reject, in one endpoint because it is one decision.
     *
     * A REASON IS REQUIRED TO REJECT. A refusal without one leaves the member
     * with nothing to act on - they cannot tell whether to photograph it again,
     * send something else, or come to the office.
     */
    public function reviewDecide(Request $request, int $document): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'reason' => ['required_if:decision,rejected', 'nullable', 'string', 'max:1000'],
        ]);

        $pending = $this->pending($document);

        try {
            $decided = $validated['decision'] === 'approved'
                ? $this->documents->approve($pending, $request->user()->id)
                : $this->documents->reject($pending, $request->user()->id, (string) $validated['reason']);
        } catch (DomainException $e) {
            throw new ApiException('DOCUMENT_REJECTED', $e->getMessage(), 422);
        }

        return response()->json(['data' => ['id' => $decided->id, 'status' => $decided->status]]);
    }

    private function pending(int $id): Document
    {
        $document = Document::find($id) ?? throw ApiException::notFound('Document');

        if ($document->status !== Document::STATUS_PENDING) {
            throw ApiException::conflict('ALREADY_DECIDED', 'That document has already been decided.');
        }

        return $document;
    }

    // ----------------------------------------------------------------- the work

    private function upload(Request $request, Model $owner): JsonResponse
    {
        $validated = $request->validate([
            /*
             * ONE SLOT PER REQUEST, named explicitly. A form that posted six
             * files at once would have to report six separate outcomes, and the
             * one that failed would be the one nobody noticed.
             */
            'slot' => ['required', 'string', 'max:40'],
            'file' => ['required', 'file'],
        ]);

        try {
            $this->documents->put(
                $owner,
                $validated['slot'],
                $request->file('file'),

                // Null when a member uploads their own - they are not a users
                // row. Today only staff reach this, but the column does not
                // assume that.
                $request->user() instanceof User ? $request->user()->id : null,
            );
        } catch (DomainException $e) {
            throw new ApiException('DOCUMENT_REJECTED', $e->getMessage(), 422);
        }

        return response()->json(['data' => $this->documents->list($owner)], 201);
    }

    private function remove(Model $owner, string $slot): JsonResponse
    {
        try {
            $this->documents->delete($owner, $slot);
        } catch (DomainException $e) {
            throw new ApiException('DOCUMENT_REJECTED', $e->getMessage(), 422);
        }

        return response()->json(['data' => $this->documents->list($owner)]);
    }

    /**
     * Streamed through the application, never exposed by URL.
     *
     * The file is on a tenant-suffixed private path and the authorisation
     * decision belongs here, not in a link somebody might forward (NFR-SEC-4).
     * That holds whether the bytes are on the local disk or in a bucket, which
     * is why the disk comes off the record rather than from config.
     */
    private function stream(
        Model $owner,
        string $slot,
        string $status = Document::STATUS_LIVE,
    ): StreamedResponse {
        try {
            $document = $this->documents->locate($owner, $slot, $status);
        } catch (DomainException $e) {
            throw ApiException::notFound('Document');
        }

        return Storage::disk($document->disk)->response(
            $document->path,
            $document->original_name,
            [
                'Content-Type' => $document->mime,

                // An identity document is not something a browser should leave
                // on the disk of a shared machine at the association office.
                'Cache-Control' => 'private, no-store',
            ],
        );
    }

    // --------------------------------------------------------- who may see what

    /** The member making the request. Staff have no "own" documents. */
    private function self(Request $request): Member
    {
        $account = $request->user();

        if (! $account instanceof Member) {
            throw ApiException::insufficientPermission('members.view');
        }

        return $account;
    }

    /**
     * A member, if this account may reach them.
     *
     * `$write` is the whole difference between reading and filing: an account
     * that may view the register should not be able to replace the photograph
     * the association identifies somebody by.
     */
    private function member(Request $request, int $id, bool $write = false): Member
    {
        $member = Member::find($id) ?? throw ApiException::notFound('Member');
        $account = $request->user();

        if ($account instanceof Member) {
            if ($account->id !== $member->id) {
                throw ApiException::notOwner('member');
            }

            // Reading only; see the class note.
            if ($write) {
                throw ApiException::insufficientPermission('members.edit');
            }

            return $member;
        }

        $permission = $write ? 'members.edit' : 'members.view';

        if (! ($account instanceof User && $account->can($permission))) {
            throw ApiException::insufficientPermission($permission);
        }

        return $member;
    }

    /**
     * A nominee, if this account may reach the member they belong to.
     *
     * Staff use `nominees.manage`, which already covers reading and writing
     * together - an association that lets somebody see who a member nominated
     * has no reason to stop them correcting it.
     */
    private function nominee(Request $request, int $id, bool $write = false): Nominee
    {
        $nominee = Nominee::find($id) ?? throw ApiException::notFound('Nominee');
        $account = $request->user();

        if ($account instanceof Member) {
            if ($nominee->member_id !== $account->id) {
                throw ApiException::notOwner('nominee');
            }

            if ($write) {
                throw ApiException::insufficientPermission('nominees.manage');
            }

            return $nominee;
        }

        if (! ($account instanceof User && $account->can('nominees.manage'))) {
            throw ApiException::insufficientPermission('nominees.manage');
        }

        return $nominee;
    }
}
