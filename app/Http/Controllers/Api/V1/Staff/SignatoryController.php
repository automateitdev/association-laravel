<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Document;
use App\Models\Tenant\Signatory;
use App\Models\User;
use App\Services\DocumentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Who signs the association's documents (legacy `signatures`, `upload-signature`).
 *
 * EVERY ROLE IS LISTED, FILLED OR NOT - the same shape as the document slots,
 * and for the same reason. "We have no secretary's signature on file" is the
 * finding, and a list of only what exists cannot show it. In the legacy
 * production data the answer is all three: `signatures` holds **0 rows**, so no
 * certificate that system ever produced has been signed.
 *
 * IT IS ADMINISTRATION, NOT MEMBER DATA, so it sits behind `settings.edit`
 * rather than `members.edit`: whose signature goes on a share certificate is
 * the committee's business, not the counter's.
 */
class SignatoryController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function index(): JsonResponse
    {
        $held = Signatory::all()->keyBy('role');

        $rows = [];

        foreach (Signatory::ROLES as $role => $label) {
            $signatory = $held->get($role);

            $rows[] = [
                'role' => $role,
                'label' => $label,
                'name' => $signatory?->name,

                /*
                 * Whether there is a SIGNATURE, which is a different question
                 * from whether there is a person. An association can record
                 * that its secretary is Md. Abdul Karim and still have nothing
                 * to print above the line.
                 *
                 * Asked of the document directly rather than through
                 * `DocumentService::list`, which builds every slot's review
                 * state: there is one slot here and only one thing to know
                 * about it.
                 */
                'has_signature' => $signatory !== null && Document::query()
                    ->where('documentable_type', $signatory->getMorphClass())
                    ->where('documentable_id', $signatory->getKey())
                    ->where('slot', 'signature')
                    ->where('status', Document::STATUS_LIVE)
                    ->exists(),
            ];
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * Record who holds a role.
     *
     * A PUT PER ROLE. The roles are a fixed set named by the society's
     * bye-laws, so there is nothing to create - only somebody to name. Clearing
     * the name removes the record, and its signature with it: a committee
     * member who has left should not keep signing certificates.
     */
    public function update(Request $request, string $role): JsonResponse
    {
        abort_unless(array_key_exists($role, Signatory::ROLES), 404);

        $validated = $request->validate([
            'name' => ['present', 'nullable', 'string', 'max:255'],
        ]);

        $existing = Signatory::where('role', $role)->first();
        $name = trim((string) $validated['name']);

        if ($name === '') {
            if ($existing !== null) {
                /*
                 * The signature goes with the person. Leaving the image behind
                 * would mean the next holder of the role inherits the last
                 * one's signature, which is the worst possible default.
                 */
                $this->forgetSignature($existing);
                $existing->delete();

                $this->audit($request, $role, 'signatory.cleared', ['name' => $existing->name], []);
            }

            return $this->index();
        }

        $before = $existing?->only(['name']) ?? [];

        Signatory::updateOrCreate(['role' => $role], ['name' => $name]);

        $this->audit($request, $role, 'signatory.recorded', $before, ['name' => $name]);

        return $this->index();
    }

    /** The signature image itself, through the same machinery as every other file. */
    public function upload(Request $request, string $role): JsonResponse
    {
        abort_unless(array_key_exists($role, Signatory::ROLES), 404);

        $signatory = Signatory::where('role', $role)->first()
            ?? throw new ApiException(
                'NO_SIGNATORY',
                'Record who holds this role before uploading their signature.',
                422,
            );

        $request->validate(['file' => ['required', 'file']]);

        try {
            $this->documents->put(
                $signatory,
                'signature',
                $request->file('file'),
                $request->user() instanceof User ? $request->user()->id : null,
            );
        } catch (DomainException $e) {
            throw new ApiException('DOCUMENT_REJECTED', $e->getMessage(), 422);
        }

        $this->audit($request, $role, 'signatory.signature_uploaded', [], ['role' => $role]);

        return $this->index();
    }

    /**
     * The signature image itself, for looking at.
     *
     * WITHOUT THIS THE UPLOAD IS UNVERIFIABLE. An association files a scan and
     * then prints forty certificates with it; being able to see what was filed,
     * before rather than after, is the difference between a mistake caught and
     * a batch reprinted. `has_signature` on the list says one exists - it
     * cannot say it is the right way up.
     */
    public function showSignature(string $role): StreamedResponse
    {
        abort_unless(array_key_exists($role, Signatory::ROLES), 404);

        $signatory = Signatory::where('role', $role)->first();

        if ($signatory === null) {
            throw ApiException::notFound('Signature');
        }

        try {
            $document = $this->documents->locate($signatory, 'signature');
        } catch (DomainException) {
            throw ApiException::notFound('Signature');
        }

        return Storage::disk($document->disk)->response(
            $document->path,
            $document->original_name,
            [
                'Content-Type' => $document->mime,

                // Not something a browser should leave on the disk of a shared
                // machine at the association office - the same rule as every
                // other document this API streams.
                'Cache-Control' => 'private, no-store',
            ],
        );
    }

    public function destroySignature(Request $request, string $role): JsonResponse
    {
        abort_unless(array_key_exists($role, Signatory::ROLES), 404);

        $signatory = Signatory::where('role', $role)->first();

        if ($signatory !== null) {
            $this->forgetSignature($signatory);
            $this->audit($request, $role, 'signatory.signature_removed', ['role' => $role], []);
        }

        return $this->index();
    }

    private function forgetSignature(Signatory $signatory): void
    {
        try {
            $this->documents->delete($signatory, 'signature');
        } catch (DomainException) {
            // There was none. Removing a signature that does not exist is the
            // outcome the caller wanted either way.
        }
    }

    private function audit(Request $request, string $role, string $action, array $before, array $after): void
    {
        AuditLog::create([
            'actor_type' => $request->user()::class,
            'actor_id' => $request->user()->id,
            'subject_type' => Signatory::class,
            'subject_id' => 0,
            'action' => $action,
            'before' => $before + ['role' => $role],
            'after' => $after,
            'ip' => $request->ip(),
        ]);
    }
}
