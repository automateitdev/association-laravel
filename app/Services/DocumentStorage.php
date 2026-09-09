<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Where an uploaded file goes, and where it turns out to have gone (ADR-0011).
 *
 * S3 WHEN CONFIGURED, LOCAL WHEN S3 FAILS
 * ---------------------------------------
 * A member who has photographed their NID on a phone and pressed Submit should
 * not lose it because a bucket was briefly unreachable, so a failed write is
 * retried locally and the request succeeds.
 *
 * THE DISK IS RETURNED, NOT ASSUMED, and that is the part that makes the
 * fallback safe rather than a trap. With two possible destinations "where is
 * this file" has two answers, and a reader that assumes one is wrong half the
 * time. Every caller stores what it is given here and reads back through it.
 *
 * THE FALLBACK IS LOGGED AT ERROR LEVEL. A fallback nobody notices is an outage
 * that runs until somebody goes looking: files pile up on a disk that is not in
 * the backup story, and the first anyone hears of it is a 404 months later.
 */
class DocumentStorage
{
    /** Where a fallback lands. Also the default when nothing else is set up. */
    public const FALLBACK_DISK = 'local';

    /**
     * Write a file, and say where it actually went.
     *
     * @return array{disk: string, path: string}
     *
     * @throws DomainException when neither disk will take it
     */
    public function put(UploadedFile $file, string $directory, string $filename): array
    {
        $preferred = $this->preferredDisk();

        if ($preferred !== self::FALLBACK_DISK) {
            try {
                return [
                    'disk' => $preferred,
                    'path' => $this->write($file, $preferred, $directory, $filename),
                ];
            } catch (Throwable $e) {
                /*
                 * Reported, with the disk named. Whoever reads this needs to
                 * know which store is unreachable, not merely that something
                 * was - and the file is now somewhere the backup schedule does
                 * not cover until documents:reconcile moves it.
                 */
                Log::error('Upload to the preferred disk failed; falling back to local storage.', [
                    'disk' => $preferred,
                    'directory' => $directory,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        try {
            return [
                'disk' => self::FALLBACK_DISK,
                'path' => $this->write($file, self::FALLBACK_DISK, $directory, $filename),
            ];
        } catch (Throwable $e) {
            /*
             * Both gone. This is the one case that must fail the request: a
             * success here would tell a member their document is filed when
             * nothing was written anywhere.
             */
            Log::error('Upload failed on every disk.', [
                'directory' => $directory,
                'exception' => $e->getMessage(),
            ]);

            throw new DomainException('That upload could not be stored. Please try again.');
        }
    }

    /**
     * Remove a file, from whichever disk holds it.
     *
     * Never throws. A document row being replaced or deleted should not be held
     * up because the old file is already gone or the store is briefly down -
     * the record is the thing that matters, and an orphaned object costs
     * pennies. Logged so it can be swept later.
     */
    public function delete(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable $e) {
            Log::warning('Could not delete a stored document; it may be orphaned.', [
                'disk' => $disk,
                'path' => $path,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function exists(string $disk, string $path): bool
    {
        try {
            return Storage::disk($disk)->exists($path);
        } catch (Throwable) {
            // An unreachable store is not the same as a missing file, but the
            // caller can do nothing different about it either way.
            return false;
        }
    }

    /**
     * The disk uploads should go to when everything is working.
     *
     * `documents.disk` rather than `filesystems.default`, because the default
     * disk is also what unrelated code writes scratch files to. Where a
     * member's NID image lives is a decision worth making on its own.
     */
    public function preferredDisk(): string
    {
        return (string) config('documents.disk', self::FALLBACK_DISK);
    }

    /**
     * A filename that is ours, not the client's.
     *
     * The uploaded name is untrusted input and has no business becoming a path.
     * It is kept as data, for display, and never used to address the file.
     */
    public function filename(string $extension): string
    {
        return Str::uuid()->toString().'.'.$extension;
    }

    private function write(UploadedFile $file, string $disk, string $directory, string $filename): string
    {
        $stored = $file->storeAs($directory, $filename, $disk);

        if ($stored === false || $stored === '') {
            // storeAs returns false rather than throwing on some drivers, and a
            // silent false here would file a document row pointing at nothing.
            throw new \RuntimeException("Disk [{$disk}] refused the write.");
        }

        return $stored;
    }
}
