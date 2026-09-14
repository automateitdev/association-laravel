<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Models\Tenant;
use App\Models\Tenant\Member;
use App\Models\Tenant\Nominee;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The members' and nominees' scans, moved from a column into a document.
 *
 * THE SECOND HALF OF THE MIGRATION, and the half that touches a filesystem.
 * `legacy:migrate` carries the database; the legacy keeps a scan as a BARE
 * FILENAME in a column - `members.nid_front` - and the file itself somewhere
 * under the old application's `storage/app/public`. Neither is much use
 * without the other, and the new schema wants both as one `documents` row so a
 * member can replace a scan and an officer can approve the replacement.
 *
 * A ROW IS ONLY WRITTEN WHEN THE FILE IS ACTUALLY COPIED. A document row
 * pointing at bytes that are not there is worse than no row: the member sees a
 * document on file, the officer opens it, and nothing happens. Where the file
 * is missing this counts it and says so, and writes nothing.
 *
 * WHERE THE LEGACY PUTS THEM, which is not where it says it does:
 *
 *   member/user           members.image
 *   member/nid            members.nid_front, members.nid_back
 *   member/signature      members.signature
 *   nominee/joinproof     members.proof_joining_cadre       <- a MEMBER's file
 *   nominee/supauthproof  members.proof_signed_by_sup_author <- also a member's
 *   nominee/user          nominees.image
 *   nominee/nid           nominees.nid_front, nominees.nid_back
 *
 * The two proofs belong to the MEMBER and live under `nominee/`. That is the
 * legacy's own constant - `Member::JOIN_PROOF = 'nominee/joinproof'` - and not
 * a mistake in reading it.
 *
 * WORSE, ONE FILE HAS TWO POSSIBLE HOMES. `proof_signed_by_sup_author` is
 * written to `SUP_AUTH_PROOF` by `MemberAuthController` and to `JOIN_PROOF` by
 * `ProfileApprovalController` - the same document in one of two directories
 * depending on which screen last saved it. So each slot carries a LIST of
 * candidate directories and the first that holds the file wins, rather than
 * one path and a shrug.
 */
class LegacyDocumentMigrator
{
    /**
     * Legacy column => [new slot, [directories to look in, in order]].
     *
     * @var array<string, array{0: string, 1: array<int, string>}>
     */
    private const MEMBER_FILES = [
        'image' => ['image', ['member/user']],
        'nid_front' => ['nid_front', ['member/nid']],
        'nid_back' => ['nid_back', ['member/nid']],
        'signature' => ['signature', ['member/signature']],
        'proof_joining_cadre' => ['proof_joining_cadre', ['nominee/joinproof']],
        'proof_signed_by_sup_author' => [
            'proof_signed_by_sup_author',
            ['nominee/supauthproof', 'nominee/joinproof'],
        ],
    ];

    /**
     * The nominee has no `signature` column in the legacy, so that slot simply
     * arrives empty. It exists in the new schema because a nominee may be asked
     * for one; nobody ever was.
     *
     * @var array<string, array{0: string, 1: array<int, string>}>
     */
    private const NOMINEE_FILES = [
        'image' => ['image', ['nominee/user']],
        'nid_front' => ['nid_front', ['nominee/nid']],
        'nid_back' => ['nid_back', ['nominee/nid']],
    ];

    private ConnectionInterface $legacy;

    private DocumentMigrationReport $report;

    /** @var callable(string):void */
    private $progress;

    public function __construct(
        private readonly string $root,
        private readonly string $connection = 'legacy',
        private readonly bool $dryRun = false,
    ) {}

    /**
     * @param  callable(string):void  $progress
     */
    public function run(Tenant $tenant, callable $progress): DocumentMigrationReport
    {
        $this->legacy = DB::connection($this->connection);
        $this->report = new DocumentMigrationReport;
        $this->progress = $progress;

        $tenant->run(function () {
            if (! $this->dryRun) {
                /*
                 * Only the rows this pass owns. A member may have sent a new
                 * scan through the app since the rehearsal, and re-running the
                 * legacy import is not a reason to delete it - so `live`
                 * documents whose `uploaded_by` is null (nobody uploaded them;
                 * they were migrated) go, and nothing else does.
                 */
                DB::table('documents')
                    ->whereNull('uploaded_by')
                    ->where('status', 'live')
                    ->delete();
            }

            $this->owners('members', Member::class, self::MEMBER_FILES);
            $this->owners('nominees', Nominee::class, self::NOMINEE_FILES);
        });

        return $this->report;
    }

    /**
     * @param  array<string, array{0: string, 1: array<int, string>}>  $map
     */
    private function owners(string $table, string $class, array $map): void
    {
        $columns = array_merge(['id'], array_keys($map));
        $rows = [];
        $now = now();

        foreach ($this->legacy->table($table)->orderBy('id')->get($columns) as $owner) {
            foreach ($map as $column => [$slot, $directories]) {
                $name = trim((string) ($owner->{$column} ?? ''));

                if ($name === '' || $name === 'null') {
                    continue;
                }

                $this->report->referenced++;

                $source = $this->locate($directories, $name);

                if ($source === null) {
                    $this->report->missing++;
                    $this->report->missingBySlot[$slot] = ($this->report->missingBySlot[$slot] ?? 0) + 1;

                    continue;
                }

                $stored = $this->copy($class, (int) $owner->id, $source, $name);

                if ($stored === null) {
                    continue;
                }

                $rows[] = [
                    'documentable_type' => $class,
                    'documentable_id' => $owner->id,
                    'slot' => $slot,

                    /*
                     * `live`, not `pending`. These are what the association
                     * already holds and has already accepted; putting 2,835
                     * scans into the review queue on day one would bury the
                     * queue and ask officers to re-approve their own records.
                     */
                    'status' => 'live',

                    'disk' => $stored['disk'],
                    'path' => $stored['path'],
                    'original_name' => $name,
                    'mime' => $stored['mime'],
                    'size' => $stored['size'],

                    // Null: nobody uploaded this through the app. It is also
                    // how a re-run knows which rows are its own to replace.
                    'uploaded_by' => null,

                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $this->report->copied++;
                $this->report->copiedBySlot[$slot] = ($this->report->copiedBySlot[$slot] ?? 0) + 1;
            }
        }

        if (! $this->dryRun && $rows !== []) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('documents')->insert($chunk);
            }
        }

        ($this->progress)(sprintf('  %-10s %5d copied', $table, count($rows)));
    }

    /** The first directory that actually holds this file, or null. */
    private function locate(array $directories, string $name): ?string
    {
        foreach ($directories as $directory) {
            $path = rtrim($this->root, '/\\').'/'.$directory.'/'.$name;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array{disk: string, path: string, mime: string, size: int}|null
     */
    private function copy(string $class, int $ownerId, string $source, string $name): ?array
    {
        $kind = $class === Nominee::class ? 'nominee' : 'member';
        $extension = pathinfo($name, PATHINFO_EXTENSION) ?: 'bin';

        // The same shape DocumentService writes, so a migrated document and one
        // sent through the app are indistinguishable afterwards - including to
        // the code that deletes them.
        $path = "documents/{$kind}/{$ownerId}/".Str::uuid()->toString().'.'.$extension;

        $size = (int) filesize($source);
        $mime = $this->mime($extension);

        if ($this->dryRun) {
            return ['disk' => 'local', 'path' => $path, 'mime' => $mime, 'size' => $size];
        }

        $handle = fopen($source, 'rb');

        if ($handle === false) {
            $this->report->unreadable++;

            return null;
        }

        Storage::disk('local')->put($path, $handle);

        if (is_resource($handle)) {
            fclose($handle);
        }

        return ['disk' => 'local', 'path' => $path, 'mime' => $mime, 'size' => $size];
    }

    /**
     * From the extension, because the legacy filenames carry one and reading
     * 3,759 files to ask libmagic would be slow for an answer the name already
     * gives. Anything unrecognised is left as a generic binary rather than
     * guessed at - a wrong `image/png` on a PDF is worse than an honest
     * octet-stream.
     */
    private function mime(string $extension): string
    {
        return match (strtolower($extension)) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}
