<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Document;
use App\Models\Tenant\Member;
use App\Models\Tenant\Nominee;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Member and nominee identity documents (parity P-10).
 *
 * THE SLOTS ARE A FIXED LIST, and that is the difference between this and the
 * payment documents beside it. A payment carries up to five slips and nobody
 * cares which is which; a member has exactly one photo, one signature and one
 * NID front, and uploading a second replaces the first. A register where a
 * member has three NID fronts and nobody knows which is current is worse than
 * one where they have none.
 *
 * WHAT IS DELIBERATELY NARROW
 * ---------------------------
 * The MIME is sniffed from the file's CONTENT, never taken from what the client
 * claimed - a renamed `.php` is not a jpeg. The stored name is a UUID. Both are
 * carried over from PaymentDocumentService, which had them for the same reason.
 *
 * The size cap is smaller than a payment slip's: an NID photograph is a phone
 * picture, and a 25 MB scan is a mistake worth stopping at the door rather than
 * storing for the life of the membership.
 */
class DocumentService
{
    /**
     * What a MEMBER may hold, and what to call each on a screen.
     *
     * Keyed to the columns the legacy left on `members`, so the names are the
     * ones the association already uses rather than new ones invented here.
     *
     * @var array<string, string>
     */
    public const MEMBER_SLOTS = [
        'image' => 'Photograph',
        'nid_front' => 'NID front',
        'nid_back' => 'NID back',
        'signature' => 'Signature',
        'proof_joining_cadre' => 'Proof of joining cadre',
        'proof_signed_by_sup_author' => 'Proof signed by supervising authority',
    ];

    /**
     * What a NOMINEE may hold.
     *
     * Fewer, because a nominee is identified rather than enrolled: the
     * association needs to know who they are and be able to prove it when the
     * time comes, not hold their employment history.
     *
     * @var array<string, string>
     */
    public const NOMINEE_SLOTS = [
        'image' => 'Photograph',
        'nid_front' => 'NID front',
        'nid_back' => 'NID back',
        'signature' => 'Signature',
    ];

    /** A phone photograph of an identity card. 5 MB is already generous. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * No PDFs, unlike a payment slip.
     *
     * These are photographs of a person, a card and a signature, and every
     * surface that displays them expects an image. A PDF here is either a scan
     * that should have been an image or a document filed in the wrong place.
     *
     * @var array<string, string>
     */
    public const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/heic' => 'heic',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly DocumentStorage $storage) {}

    /**
     * The slots this kind of owner may hold.
     *
     * @return array<string, string>
     */
    public function slotsFor(Model $owner): array
    {
        return match (true) {
            $owner instanceof Member => self::MEMBER_SLOTS,
            $owner instanceof Nominee => self::NOMINEE_SLOTS,
            default => throw new DomainException('That record does not carry documents.'),
        };
    }

    /**
     * File a document, replacing whatever was in that slot.
     *
     * THE OLD FILE IS DELETED AFTER THE NEW ROW IS COMMITTED, never before. The
     * other order loses the only copy if the write fails - and an identity
     * document a member had to visit the office to provide is not something to
     * lose because a bucket blinked.
     */
    public function put(
        Model $owner,
        string $slot,
        UploadedFile $file,
        ?int $uploadedBy = null,
        string $status = Document::STATUS_LIVE,
    ): Document {
        $slots = $this->slotsFor($owner);

        if (! array_key_exists($slot, $slots)) {
            throw new DomainException(
                "There is no [{$slot}] document. Expected one of: ".implode(', ', array_keys($slots)).'.'
            );
        }

        $this->guard($file);

        $stored = $this->storage->put(
            $file,
            $this->directory($owner),
            $this->storage->filename(self::ALLOWED_MIME[$file->getMimeType()]),
        );

        $previous = $this->find($owner, $slot, $status);

        $document = DB::transaction(fn () => Document::updateOrCreate(
            [
                'documentable_type' => $owner->getMorphClass(),
                'documentable_id' => $owner->getKey(),
                'slot' => $slot,
                'status' => $status,
            ],
            [
                'disk' => $stored['disk'],
                'path' => $stored['path'],
                'original_name' => $this->safeName($file->getClientOriginalName()),
                'mime' => $file->getMimeType(),
                'size' => (int) $file->getSize(),
                'uploaded_by' => $uploadedBy,

                // A resubmission is a fresh request, not a decided one.
                'decision_reason' => null,
                'decided_by' => null,
                'decided_at' => null,
            ],
        ));

        if ($previous && $previous->path !== $stored['path']) {
            $this->storage->delete($previous->disk, $previous->path);
        }

        return $document;
    }

    /**
     * A member's own submission, waiting on an officer (FR-MEM-8).
     *
     * The LIVE document is untouched. That is the point of the queue: the
     * office keeps working from the NID it already has while the replacement
     * waits, rather than the register briefly holding whatever was last
     * uploaded by whoever was holding the phone.
     */
    public function submit(Model $owner, string $slot, UploadedFile $file): Document
    {
        return $this->put($owner, $slot, $file, null, Document::STATUS_PENDING);
    }

    /**
     * Accept a submission: it becomes the document the association holds.
     *
     * THE OLD ONE GOES ONLY ONCE THE NEW ONE IS LIVE. Deleting first would
     * leave a slot empty if anything failed in between, and an identity
     * document a member had to visit the office to provide is not something to
     * lose to an interrupted transaction.
     */
    public function approve(Document $pending, int $decidedBy): Document
    {
        $this->assertPending($pending);

        $superseded = Document::query()
            ->where('documentable_type', $pending->documentable_type)
            ->where('documentable_id', $pending->documentable_id)
            ->where('slot', $pending->slot)
            ->where('status', Document::STATUS_LIVE)
            ->first();

        DB::transaction(function () use ($pending, $superseded, $decidedBy) {
            /*
             * The live row is deleted rather than marked rejected: it was never
             * rejected, it was replaced. Keeping it would put a second copy of
             * the member's identity document in a table nobody reads, for the
             * life of the membership.
             */
            $superseded?->delete();

            $pending->update([
                'status' => Document::STATUS_LIVE,
                'decided_by' => $decidedBy,
                'decided_at' => now(),
                'decision_reason' => null,
            ]);
        });

        if ($superseded && $superseded->hasFile()) {
            $this->storage->delete($superseded->disk, $superseded->path);
        }

        return $pending->refresh();
    }

    /**
     * Refuse a submission, and say why.
     *
     * A REASON IS REQUIRED, because a refusal without one leaves the member
     * with nothing to act on: they cannot tell whether to photograph it again,
     * send a different document, or come to the office. The metadata is kept so
     * they can see what happened; the FILE is deleted, because an image the
     * association has refused is not one to keep storing.
     */
    public function reject(Document $pending, int $decidedBy, string $reason): Document
    {
        $this->assertPending($pending);

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('Say why it was not accepted, so the member can act on it.');
        }

        $disk = $pending->disk;
        $path = $pending->path;

        $pending->update([
            'status' => Document::STATUS_REJECTED,
            'disk' => null,
            'path' => null,
            'decision_reason' => $reason,
            'decided_by' => $decidedBy,
            'decided_at' => now(),
        ]);

        if ($disk !== null && $path !== null) {
            $this->storage->delete($disk, $path);
        }

        return $pending->refresh();
    }

    /**
     * Everything waiting on a decision, across every member.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Document>
     */
    public function queue()
    {
        return Document::query()
            ->where('status', Document::STATUS_PENDING)
            ->with('documentable')
            ->orderBy('created_at')
            ->get();
    }

    private function assertPending(Document $document): void
    {
        if ($document->status !== Document::STATUS_PENDING) {
            throw new DomainException('That document has already been decided.');
        }
    }

    /**
     * What this owner holds, as metadata a client may see.
     *
     * EVERY SLOT IS LISTED, filled or not. A screen that only lists what exists
     * cannot show what is missing, and "which members still owe us an NID" is
     * the question an office actually asks.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(Model $owner): array
    {
        $documents = $this->all($owner);

        $live = $documents->where('status', Document::STATUS_LIVE)->keyBy('slot');
        $pending = $documents->where('status', Document::STATUS_PENDING)->keyBy('slot');

        /*
         * The most recent refusal per slot, and only while nothing newer is
         * waiting. A member needs to see WHY their upload came back, and needs
         * to stop seeing it once they have submitted again - otherwise the
         * screen carries an old complaint about a document that no longer
         * exists.
         */
        $rejected = $documents
            ->where('status', Document::STATUS_REJECTED)
            ->sortByDesc('decided_at')
            ->groupBy('slot')
            ->map(fn ($group) => $group->first());

        return collect($this->slotsFor($owner))
            ->map(function (string $label, string $slot) use ($live, $pending, $rejected) {
                $document = $live->get($slot);
                $waiting = $pending->get($slot);
                $refused = $waiting === null ? $rejected->get($slot) : null;

                return [
                    'slot' => $slot,
                    'label' => $label,
                    'uploaded' => $document !== null,

                    // Never the path. That is ours, not the client's.
                    'original_name' => $document?->original_name,
                    'mime' => $document?->mime,
                    'size' => $document?->size,
                    'uploaded_at' => $document?->created_at?->toIso8601String(),

                    /*
                     * The review, flattened onto the slot rather than returned
                     * as a separate list. A screen asking "what is the state of
                     * this document" should not have to join two collections.
                     */
                    'pending' => $waiting !== null,
                    'pending_id' => $waiting?->id,
                    'pending_at' => $waiting?->created_at?->toIso8601String(),
                    'rejected_reason' => $refused?->decision_reason,
                    'rejected_at' => $refused?->decided_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The document in a slot, ready to stream.
     *
     * @throws DomainException when the slot is empty or the file has gone
     */
    public function locate(
        Model $owner,
        string $slot,
        string $status = Document::STATUS_LIVE,
    ): Document {
        $document = $this->find($owner, $slot, $status)
            ?? throw new DomainException('No document has been uploaded for that.');

        if (! $document->hasFile() || ! $this->storage->exists($document->disk, $document->path)) {
            /*
             * The row outlived the file. Said plainly rather than as a 404,
             * because the two mean different things to whoever is looking: one
             * is "nobody uploaded it", the other is "something lost it".
             */
            throw new DomainException('That document is recorded but is no longer stored.');
        }

        return $document;
    }

    public function delete(Model $owner, string $slot): void
    {
        $document = $this->find($owner, $slot)
            ?? throw new DomainException('No document has been uploaded for that.');

        $disk = $document->disk;
        $path = $document->path;

        $document->delete();

        // After the row, for the same reason as a replacement: a file still
        // there with no row is tidier than a row pointing at nothing.
        $this->storage->delete($disk, $path);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Document> */
    public function all(Model $owner)
    {
        return Document::query()
            ->where('documentable_type', $owner->getMorphClass())
            ->where('documentable_id', $owner->getKey())
            ->get();
    }

    private function find(
        Model $owner,
        string $slot,
        string $status = Document::STATUS_LIVE,
    ): ?Document {
        return Document::query()
            ->where('documentable_type', $owner->getMorphClass())
            ->where('documentable_id', $owner->getKey())
            ->where('slot', $slot)
            ->where('status', $status)
            ->first();
    }

    private function guard(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new DomainException('That upload did not complete. Please try again.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new DomainException(
                'Each document must be under '.(self::MAX_BYTES / 1024 / 1024).' MB.'
            );
        }

        // getMimeType() sniffs the content; getClientMimeType() is whatever the
        // client claimed. Check the former, or a renamed .php is a "jpeg".
        if (! array_key_exists((string) $file->getMimeType(), self::ALLOWED_MIME)) {
            throw new DomainException('Documents must be a photo (JPEG, PNG, HEIC or WebP).');
        }
    }

    /**
     * Per owner, under the tenant's own storage root.
     *
     * The tenancy filesystem bootstrapper already suffixes the disk root with
     * the tenant, so one association physically cannot address another's files.
     * This only has to be unique within an association.
     */
    private function directory(Model $owner): string
    {
        $kind = $owner instanceof Nominee ? 'nominee' : 'member';

        return "documents/{$kind}/{$owner->getKey()}";
    }

    private function safeName(string $name): string
    {
        return \Illuminate\Support\Str::limit(
            preg_replace('/[^\w\s.\-()]/u', '', $name) ?: 'document',
            120,
            '',
        );
    }
}
