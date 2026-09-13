<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Document;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberPreference;
use App\Models\Tenant\MemberProfileUpdate;
use App\Models\Tenant\Nominee;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The queue of changes members have asked the office to make (FR-MEM-8).
 *
 * EVERY REQUEST IS SHOWN AS A BEFORE AND AFTER. An officer approving "mobile:
 * 01712345678" cannot judge it without knowing what the number is now - a
 * digit changed and a number replaced entirely are different decisions, and
 * only one of them is likely to be somebody taking over an account.
 *
 * A DECISION IS FINAL AND KEPT. Approving applies the change; rejecting applies
 * nothing. Either way the request stays, with who decided it and why, because
 * "I asked you to change my address in March" needs an answer.
 */
class ProfileUpdateController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function index(Request $request): JsonResponse
    {
        $updates = MemberProfileUpdate::query()
            ->with('member:id,name,mobile')
            ->when(
                $request->query('status', MemberProfileUpdate::STATUS_PENDING) !== 'all',
                fn ($q) => $q->where('status', $request->query('status', MemberProfileUpdate::STATUS_PENDING))
            )
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'data' => $updates->getCollection()->map(fn (MemberProfileUpdate $u) => $this->shape($u)),
            'meta' => [
                'current_page' => $updates->currentPage(),
                'total' => $updates->total(),
                'last_page' => $updates->lastPage(),
                'per_page' => $updates->perPage(),
                'pending' => MemberProfileUpdate::pending()->count(),
            ],
        ]);
    }

    /**
     * Approve or reject.
     *
     * A REASON IS REQUIRED TO REJECT, and not to approve. Refusing somebody's
     * request without saying why leaves them to guess and ask again; approving
     * needs no explanation because the result speaks for itself.
     */
    public function decide(Request $request, int $update): JsonResponse
    {
        $record = MemberProfileUpdate::with('member')->findOrFail($update);

        if (! $record->isPending()) {
            throw new ApiException(
                'ALREADY_DECIDED',
                "This request was already {$record->status} on {$record->decided_at?->toDateString()}.",
                422,
            );
        }

        $validated = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:500'],
        ], [
            'reason.required_if' => 'Say why it was refused - the member will ask.',
        ]);

        $approving = $validated['decision'] === 'approve';
        $member = $record->member;
        $before = [];

        DB::transaction(function () use ($record, $member, $approving, $validated, $request, &$before) {
            if ($approving) {
                /*
                 * Filtered against ALLOWED again at the moment of applying, not
                 * only when submitted. A request could have been written when
                 * the list was wider, and what is applied is what matters.
                 */
                $changes = array_intersect_key(
                    $record->changes,
                    array_flip(MemberProfileUpdate::ALLOWED)
                );

                $before = array_intersect_key($member->only(array_keys($changes)), $changes);

                if ($changes !== []) {
                    $member->update($changes);
                }

                /*
                 * THE NOMINEE, from the same row and the same decision.
                 *
                 * Filtered against NOMINEE_ALLOWED here as well as on the way
                 * in, for the reason given above: a request could have been
                 * written when the list was wider, and what is applied is what
                 * matters.
                 *
                 * updateOrCreate on the FIRST nominee, because "add" and
                 * "change" are the same act from the member's side - they fill
                 * in the section and ask. A member with no nominee yet gets
                 * one; a member with several keeps the rest, which staff
                 * manage separately.
                 */
                $nomineeChanges = MemberProfileUpdate::nomineeChanges($record->changes);

                if ($nomineeChanges !== []) {
                    $nominee = $member->nominees()->orderBy('id')->first();

                    $before += $nominee
                        ? collect($nomineeChanges)
                            ->mapWithKeys(fn ($_, string $field) => [
                                MemberProfileUpdate::NOMINEE_PREFIX.$field => $nominee->{$field},
                            ])
                            ->all()
                        : [];

                    if ($nominee) {
                        $nominee->update($nomineeChanges);
                    } else {
                        $nominee = $member->nominees()->create($nomineeChanges);
                    }

                    /*
                     * THE FILES FOLLOW THE PERSON THEY DESCRIBE.
                     *
                     * A member naming their FIRST nominee has nobody to
                     * attach an NID to - the row is created here, on
                     * approval - so the submission hangs off this request
                     * instead, and moves across once there is somebody to own
                     * it. See DocumentController::nomineeOwner.
                     *
                     * Re-pointed rather than copied: it is the same file, and
                     * a second row would leave an officer two identical
                     * photographs to decide between.
                     */
                    Document::query()
                        ->where('documentable_type', MemberProfileUpdate::class)
                        ->where('documentable_id', $record->id)
                        ->update([
                            'documentable_type' => Nominee::class,
                            'documentable_id' => $nominee->id,
                        ]);
                }

                /*
                 * THE HOUSING PREFERENCES, one row per project.
                 *
                 * updateOrCreate for the same reason as the nominee: from the
                 * member's side "answer this project" and "change my answer"
                 * are the same act. An answer emptied of everything is DELETED
                 * rather than kept as a row of nulls - the staff endpoint does
                 * the same, and a row that says nothing would still be counted
                 * as an answered project by anything that counts them.
                 */
                $preferenceChanges = MemberProfileUpdate::preferenceChanges($record->changes);

                foreach ($preferenceChanges as $project => $fields) {
                    $existing = MemberPreference::query()
                        ->where('member_id', $member->id)
                        ->where('project', $project)
                        ->first();

                    $before += collect($fields)
                        ->mapWithKeys(fn ($_, string $field) => [
                            MemberProfileUpdate::preferenceKey($project, $field) => $existing?->{$field},
                        ])
                        ->all();

                    /*
                     * Merged with what is already there before asking whether
                     * anything is left. A member clearing ONE field of an
                     * answered project has not cleared the project, and
                     * judging the incoming fields alone would delete the row.
                     */
                    $merged = new MemberPreference(array_merge(
                        $existing?->only(MemberProfileUpdate::PREFERENCE_ALLOWED) ?? [],
                        $fields,
                        ['member_id' => $member->id, 'project' => $project],
                    ));

                    if (! $merged->isAnswered()) {
                        $existing?->delete();

                        continue;
                    }

                    MemberPreference::updateOrCreate(
                        ['member_id' => $member->id, 'project' => $project],
                        $fields,
                    );
                }
            }

            /*
             * A REFUSED REQUEST TAKES ITS ATTACHMENTS WITH IT.
             *
             * Files hung off this row were sent for a nominee the office has
             * just declined to record. Leaving them would orphan an identity
             * document against a dead request - unreachable by the member,
             * invisible to staff, and still on disk. The member re-sends with
             * the correction, which is the same thing they do with the name.
             */
            if (! $approving) {
                foreach (Document::query()
                    ->where('documentable_type', MemberProfileUpdate::class)
                    ->where('documentable_id', $record->id)
                    ->get() as $attachment) {
                    $this->documents->discard($attachment);
                }
            }

            $record->update([
                'status' => $approving
                    ? MemberProfileUpdate::STATUS_APPROVED
                    : MemberProfileUpdate::STATUS_REJECTED,
                'decision_reason' => $validated['reason'] ?? null,
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
            ]);
        });

        /*
         * Audited against the MEMBER, not the request. In a year, the question
         * is "when did this member's mobile number change and who allowed it",
         * and a log entry filed under a request id nobody remembers does not
         * answer it.
         */
        AuditLog::create([
            'actor_type' => $request->user()::class,
            'actor_id' => $request->user()->id,
            'subject_type' => Member::class,
            'subject_id' => $member->id,
            'action' => $approving ? 'member.profile_update_approved' : 'member.profile_update_rejected',
            'before' => $before,
            'after' => $approving ? $record->changes : [],
            'reason' => $validated['reason'] ?? null,
            'ip' => $request->ip(),
        ]);

        return response()->json(['data' => $this->shape($record->fresh(['member']))]);
    }

    /** What the record holds today, for whichever thing the key names. */
    private function currentValue(
        string $field,
        ?Member $member,
        ?Nominee $nominee,
        Collection $preferences,
    ): mixed {
        if (str_starts_with($field, MemberProfileUpdate::PREFERENCE_PREFIX)) {
            $rest = substr($field, strlen(MemberProfileUpdate::PREFERENCE_PREFIX));

            if (! str_contains($rest, ':')) {
                return null;
            }

            [$project, $name] = explode(':', $rest, 2);

            return $preferences->get($project)?->{$name};
        }

        if (str_starts_with($field, MemberProfileUpdate::NOMINEE_PREFIX)) {
            return $nominee?->{substr($field, strlen(MemberProfileUpdate::NOMINEE_PREFIX))};
        }

        return $member?->{$field};
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(MemberProfileUpdate $update): array
    {
        $member = $update->member;

        /*
         * Each field as a pair. A screen showing only the proposed value asks
         * an officer to approve a change they cannot see the shape of.
         */
        $fields = [];

        /*
         * WHERE "CURRENT" COMES FROM DEPENDS ON THE KEY.
         *
         * This read `$member->{$field}` for everything, which is right for the
         * member's own columns and silently wrong for the rest: a
         * `nominee_name` key asked the MEMBER for a `nominee_name` attribute,
         * got null, and showed an officer a blank where the nominee's current
         * name should be. Approving a change you cannot see the shape of is
         * exactly what these pairs exist to prevent.
         */
        $nominee = $member?->nominees()->orderBy('id')->first();

        $preferences = $member
            ? MemberPreference::query()->where('member_id', $member->id)->get()->keyBy('project')
            : collect();

        foreach ($update->changes as $field => $proposed) {
            $fields[] = [
                'field' => $field,
                'current' => $this->currentValue($field, $member, $nominee, $preferences),
                'proposed' => $proposed,
            ];
        }

        /*
         * WHAT CAME WITH IT.
         *
         * A member naming their first nominee attaches the NID to the REQUEST
         * - there is nobody to attach it to yet - and an officer deciding the
         * name could not see it. They would have approved "add Firoza Khatun
         * as sister" on the strength of the name alone, with the document
         * proving it sitting on another screen under a queue they had no
         * reason to connect to this.
         *
         * The file itself is not inlined: it is fetched from the review
         * endpoint that already streams it, which keeps this list cheap and
         * keeps one route serving one file.
         */
        $attachments = Document::query()
            ->where('documentable_type', MemberProfileUpdate::class)
            ->where('documentable_id', $update->id)
            ->get()
            ->map(fn (Document $document) => [
                'id' => $document->id,
                'slot' => $document->slot,
                'label' => DocumentService::NOMINEE_SLOTS[$document->slot] ?? $document->slot,
                'status' => $document->status,
                'original_name' => $document->original_name,
                'mime' => $document->mime,
                'size' => $document->size,
            ])
            ->all();

        return [
            'id' => $update->id,
            'member_id' => $update->member_id,
            'attachments' => $attachments,
            'member_name' => $member?->name,
            'member_mobile' => $member?->mobile,
            'fields' => $fields,
            'status' => $update->status,
            'decision_reason' => $update->decision_reason,
            'decided_at' => $update->decided_at?->toDateTimeString(),
            'requested_at' => $update->created_at?->toDateTimeString(),
        ];
    }
}
