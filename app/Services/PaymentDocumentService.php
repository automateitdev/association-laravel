<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\PaymentInfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Proof-of-payment documents: bank slips, deposit receipts, transfer
 * screenshots.
 *
 * With no gateway integrated, this is how money actually reaches an
 * association through the app: the member pays at a bank, photographs the
 * slip, and staff approve against it. That makes these files evidence, not
 * attachments - an approval decision rests on them, and a member may be asked
 * about one months later.
 *
 * STORAGE (NFR-SEC-4)
 * -------------------
 * The `local` disk is tenant-suffixed by stancl's filesystem bootstrapper, so
 * files land under storage/tenant<slug>/app/ and one association physically
 * cannot read another's uploads. Nothing is ever written to a public path, and
 * nothing is served by URL: every download goes through a controller that
 * checks ownership or staff permission first.
 */
class PaymentDocumentService
{
    /**
     * The disk a document recorded before ADR-0011 is on.
     *
     * Kept as a constant rather than deleted: every row written before uploads
     * could go to S3 has no `disk` of its own, and "local" is the only correct
     * answer for those. New rows carry whichever disk actually took them.
     */
    public const DISK = 'local';

    /** Slips are photographed on phones; 8 MB is generous for that. */
    public const MAX_BYTES = 8 * 1024 * 1024;

    public const MAX_PER_PAYMENT = 5;

    /**
     * Deliberately narrow. A bank slip is a photo or a PDF; anything else is
     * either a mistake or an attempt at something else.
     */
    public const ALLOWED_MIME = [
        'image/jpeg',
        'image/png',
        'image/heic',
        'image/webp',
        'application/pdf',
    ];

    /**
     * Attach documents to a payment.
     *
     * @param  array<UploadedFile>  $files
     * @return array<int, array<string, mixed>> the payment's full document list
     */
    public function __construct(private readonly DocumentStorage $storage) {}

    public function attach(PaymentInfo $payment, array $files): array
    {
        $existing = $payment->documents ?? [];

        if (count($existing) + count($files) > self::MAX_PER_PAYMENT) {
            throw new \DomainException(
                'A payment may carry at most '.self::MAX_PER_PAYMENT.' documents.'
            );
        }

        foreach ($files as $file) {
            $this->guard($file);

            /*
             * Through DocumentStorage since ADR-0011: S3 when it is configured,
             * local when that fails, and the disk it actually used comes back
             * rather than being assumed. The random filename is unchanged and
             * is still the point - the member's own filename is untrusted input
             * and has no business becoming a path.
             */
            $stored = $this->storage->put(
                $file,
                $this->directory($payment),
                $this->storage->filename($this->extensionFor($file)),
            );

            $existing[] = [
                'disk' => $stored['disk'],
                'path' => $stored['path'],
                'original_name' => $this->safeName($file->getClientOriginalName()),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_at' => now()->toIso8601String(),
            ];
        }

        $payment->update(['documents' => $existing]);

        return $existing;
    }

    /**
     * Metadata for display. Never the path - that is ours, not the client's.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(PaymentInfo $payment): array
    {
        return collect($payment->documents ?? [])
            ->values()
            ->map(fn (array $doc, int $index) => [
                'index' => $index,
                'original_name' => $doc['original_name'] ?? 'document',
                'mime' => $doc['mime'] ?? null,
                'size' => $doc['size'] ?? null,
                'uploaded_at' => $doc['uploaded_at'] ?? null,
            ])
            ->all();
    }

    /**
     * @return array{disk: string, path: string, name: string, mime: string}
     */
    public function locate(PaymentInfo $payment, int $index): array
    {
        $documents = $payment->documents ?? [];

        if (! isset($documents[$index])) {
            throw new \DomainException('No such document on this payment.');
        }

        $doc = $documents[$index];

        // Rows written before ADR-0011 carry no disk, and local is where they
        // are. Reading it off the record is what lets the two coexist.
        $disk = $doc['disk'] ?? self::DISK;

        if (! Storage::disk($disk)->exists($doc['path'])) {
            throw new \DomainException('That document is no longer stored.');
        }

        return [
            'disk' => $disk,
            'path' => $doc['path'],
            'name' => $doc['original_name'] ?? 'document',
            'mime' => $doc['mime'] ?? 'application/octet-stream',
        ];
    }

    private function guard(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new \DomainException('That upload did not complete. Please try again.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new \DomainException(
                'Each document must be under '.(self::MAX_BYTES / 1024 / 1024).' MB.'
            );
        }

        // getMimeType() sniffs the actual content; getClientMimeType() is
        // whatever the client claimed. Check the former, or a renamed .php is a
        // "jpeg".
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME, true)) {
            throw new \DomainException(
                'Documents must be a photo (JPEG, PNG, HEIC, WebP) or a PDF.'
            );
        }
    }

    private function extensionFor(UploadedFile $file): string
    {
        return match ($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/heic' => 'heic',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }

    private function directory(PaymentInfo $payment): string
    {
        return "payment-documents/{$payment->id}";
    }

    private function safeName(string $name): string
    {
        return Str::limit(preg_replace('/[^\w\s.\-()]/u', '', $name) ?: 'document', 120, '');
    }
}
