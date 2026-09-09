<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member and nominee identity documents (parity P-10).
 *
 * WHY A TABLE AND NOT THE COLUMNS THAT ALREADY EXIST
 * --------------------------------------------------
 * `members` carries `image`, `nid_front`, `nid_back`, `signature` and the two
 * proofs, and they are in the model's fillable list - carried over from the
 * legacy schema, where each holds a bare filename. Nothing has ever written
 * them, which is what parity P-10 records.
 *
 * They cannot be used as they stand, because ADR-0011 requires the DISK to be
 * recorded with every file: uploads go to S3 when it is configured and fall
 * back to local when it fails, so "where is this file" has two possible
 * answers. A single string column cannot hold that, nor the MIME type the
 * download needs, nor the original filename a person recognises.
 *
 * The old columns are left alone rather than dropped. They are unused and
 * harmless, and dropping them is a separate decision from adding this.
 *
 * ONE TABLE FOR BOTH OWNERS. Nominees need NID images too - the legacy carries
 * applicant and nominee NIDs through the same approval queue - and the nominees
 * table has only a single `image` column. A polymorphic owner covers both
 * without adding another five columns there, and gives anything added later
 * (guarantors, association documents) the same shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();

            // Member or Nominee today. Indexed together because every read is
            // "the documents belonging to this one owner".
            $table->morphs('documentable');

            /*
             * WHICH document this is - `nid_front`, `signature` and so on, not
             * a free-for-all list. A member has one photo and one signature;
             * uploading a second replaces the first rather than accumulating,
             * which is what the unique index below enforces.
             */
            $table->string('slot', 40);

            /*
             * ADR-0011. Not a guess and not a default: whichever disk actually
             * took the file, so a read never has to try both.
             */
            $table->string('disk', 20);
            $table->string('path');

            // Shown to people. Never used to address the file - the stored name
            // is a UUID, because an uploaded filename is untrusted input.
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size');

            /*
             * Who filed it. Nullable because a member uploading their own
             * document is not a `users` row, and because a document can outlive
             * the staff account that added it.
             */
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One document per slot per owner. A replacement overwrites the row
            // and deletes the old file; there is no history here, and a member's
            // superseded NID image is not something to keep by default.
            $table->unique(['documentable_type', 'documentable_id', 'slot'], 'uniq_owner_slot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
