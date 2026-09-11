<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who signs the association's documents (legacy `signatures`).
 *
 * The share certificate and the ID card carry two signatures - a secretary and
 * a chairman - and this is where they come from.
 *
 * THE LEGACY VERSION IS A TITLE AND A FILE PATH, and the certificate template
 * looks them up by matching `title == 'secretary'` and `title == 'chairman'`
 * against a lower-cased free-text column. Two things follow from that: the
 * association can only ever have the two roles somebody hardcoded, and a title
 * typed as `Secretary` or `secretary ` matches nothing and the signature
 * silently does not appear. `signatures` holds **0 rows in production**, so no
 * certificate that system ever produced has been signed at all.
 *
 * SO THE ROLE IS AN ENUM AND THE NAME IS A COLUMN. An association signs with
 * people, and a document says who signed it: "Md. Abdul Karim, Secretary" is
 * what belongs under a signature line, not "secretary" alone.
 *
 * THE IMAGE IS A DOCUMENT, not a path. `documents` already carries the disk,
 * the mime, the size and the uploader, and already knows how to put bytes on
 * S3 or on local storage and read them back - see ADR-0011. A second, weaker
 * copy of that for signature images would be the one place a file's disk is
 * not recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signatories', function (Blueprint $table) {
            $table->id();

            /*
             * The roles a cooperative society's documents are signed by, as its
             * bye-laws name them. An enum rather than free text because the
             * templates place them: a certificate puts the secretary on the
             * left and the chairman on the right, and it cannot do that with a
             * column somebody typed.
             */
            $table->enum('role', ['chairman', 'secretary', 'treasurer'])->unique();

            // Printed under the line. The signature alone identifies nobody a
            // year later, when the committee has changed.
            $table->string('name');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signatories');
    }
};
