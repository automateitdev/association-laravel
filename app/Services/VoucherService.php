<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Ledger;
use App\Models\Tenant\LedgerTrace;
use App\Models\Tenant\Voucher;
use App\Models\Tenant\VoucherLine;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Writing, approving and reversing vouchers (FR-ACC-4, FR-ACC-6).
 *
 * THE ONE RULE EVERYTHING ELSE SERVES: an unbalanced document never reaches the
 * ledger. A payment cannot post unbalanced because the code builds both sides;
 * a voucher is typed by a person, so this is where that guarantee is actually
 * earned.
 *
 * Balance is checked TWICE - when a draft is saved and again at approval - and
 * that is not redundant. A draft can be edited between the two, and the check
 * that matters is the one immediately before the entries exist.
 *
 * Arithmetic is bcmath on strings throughout. Summing a column of typed money
 * as floats is how a voucher that balances on screen fails to balance in the
 * ledger by a paisa.
 */
class VoucherService
{
    /**
     * @param  list<array{ledger_id: int, debit?: string|float|null, credit?: string|float|null, narration?: string|null}>  $lines
     */
    public function draft(array $attributes, array $lines, ?int $createdBy = null): Voucher
    {
        $normalised = $this->normalise($lines);

        $this->assertBalanced($normalised);

        return DB::transaction(function () use ($attributes, $normalised, $createdBy) {
            $voucher = Voucher::create([
                'voucher_no' => $attributes['voucher_no'] ?? $this->nextNumber($attributes['type']),
                'type' => $attributes['type'],
                'voucher_date' => $attributes['voucher_date'],
                'narration' => $attributes['narration'] ?? null,
                'status' => Voucher::STATUS_DRAFT,
                'created_by' => $createdBy,
            ]);

            foreach ($normalised as $line) {
                $voucher->lines()->create($line);
            }

            return $voucher->load('lines');
        });
    }

    /**
     * Replace a draft's contents.
     *
     * Only a draft. An approved voucher's entries are in the ledger and have
     * been reported on; editing one would change history silently, which is
     * what reversal exists to avoid.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(Voucher $voucher, array $attributes, array $lines): Voucher
    {
        $this->assertDraft($voucher, 'changed');

        $normalised = $this->normalise($lines);
        $this->assertBalanced($normalised);

        return DB::transaction(function () use ($voucher, $attributes, $normalised) {
            $voucher->update([
                'type' => $attributes['type'] ?? $voucher->type,
                'voucher_date' => $attributes['voucher_date'] ?? $voucher->voucher_date,
                'narration' => $attributes['narration'] ?? $voucher->narration,
            ]);

            // Replaced wholesale rather than diffed: a voucher is one document,
            // and a half-updated set of lines is not a state worth reaching.
            $voucher->lines()->delete();

            foreach ($normalised as $line) {
                $voucher->lines()->create($line);
            }

            return $voucher->fresh('lines');
        });
    }

    /**
     * Approve, and post to the ledger.
     *
     * The posting and the status change are one transaction. A voucher marked
     * approved whose entries failed to write would be a document everybody
     * believes is in the accounts and is not - the exact shape of D-20, where
     * payments completed and their postings silently did not.
     */
    public function approve(Voucher $voucher, int $approvedBy): Voucher
    {
        $this->assertDraft($voucher, 'approved');

        $lines = $voucher->lines()->get();

        if ($lines->isEmpty()) {
            throw new DomainException('This voucher has no lines, so there is nothing to post.');
        }

        // Re-checked here, not only when it was drafted: the draft may have
        // been edited since, and this is the last moment before it is real.
        $this->assertBalanced($lines->map(fn (VoucherLine $l) => [
            'ledger_id' => $l->ledger_id,
            'debit' => (string) $l->debit,
            'credit' => (string) $l->credit,
        ])->all());

        return DB::transaction(function () use ($voucher, $lines, $approvedBy) {
            foreach ($lines as $line) {
                LedgerTrace::create([
                    'ledger_id' => $line->ledger_id,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                    'source_type' => Voucher::class,
                    'source_id' => $voucher->id,
                    'reference' => $voucher->voucher_no,
                    'posted_on' => $voucher->voucher_date,
                    'narration' => $line->narration ?? $voucher->narration,
                ]);
            }

            $voucher->update([
                'status' => Voucher::STATUS_APPROVED,
                'approved_by' => $approvedBy,
                'approved_at' => now(),
            ]);

            return $voucher->fresh(['lines']);
        });
    }

    /** Rejecting posts nothing; the document stays, refused. */
    public function reject(Voucher $voucher, int $rejectedBy): Voucher
    {
        $this->assertDraft($voucher, 'rejected');

        $voucher->update([
            'status' => Voucher::STATUS_REJECTED,
            'approved_by' => $rejectedBy,
            'approved_at' => now(),
        ]);

        return $voucher->fresh();
    }

    /**
     * Undo an approved voucher by posting its mirror image (FR-ACC-6).
     *
     * NOT A DELETION. The original entries stay exactly where they are, and a
     * new voucher posts the opposite of each, with every reversing trace
     * pointing at the one it undoes through `reverses_id`. A report run last
     * month still reconciles with what it said; the correction is visible as a
     * dated act by a named person, which is what an auditor is entitled to.
     */
    public function reverse(Voucher $voucher, int $reversedBy, string $reason): Voucher
    {
        if (! $voucher->isApproved()) {
            throw new DomainException(
                'Only an approved voucher can be reversed. A draft can simply be rejected.'
            );
        }

        $existing = $voucher->traces()->whereNull('reverses_id')->get();

        if ($existing->isEmpty()) {
            throw new DomainException('This voucher posted nothing, so there is nothing to reverse.');
        }

        /*
         * Asked of the ORIGINAL's traces, not the voucher's own.
         *
         * A reversing trace belongs to the reversal voucher, so looking for one
         * among this voucher's traces finds nothing and lets the same document
         * be reversed twice - which cancels it and then cancels the
         * cancellation, leaving the ledger looking untouched and two
         * unexplained pairs of entries in it.
         *
         * The document-level link is asked as well, and either answer is enough
         * to refuse. They are written in the same transaction so they cannot
         * disagree about a voucher reversed by this code; asking both means a
         * row that predates `vouchers.reverses_id`, or one whose traces were
         * ever moved, is still protected.
         */
        if (
            LedgerTrace::whereIn('reverses_id', $existing->pluck('id'))->exists()
            || Voucher::where('reverses_id', $voucher->id)->exists()
        ) {
            throw new DomainException('This voucher has already been reversed.');
        }

        return DB::transaction(function () use ($voucher, $existing, $reversedBy, $reason) {
            $reversal = Voucher::create([
                'voucher_no' => $this->nextNumber($voucher->type, 'REV'),
                'type' => $voucher->type,
                'voucher_date' => now()->toDateString(),
                'narration' => "Reversal of {$voucher->voucher_no}: {$reason}",
                'status' => Voucher::STATUS_APPROVED,
                'reverses_id' => $voucher->id,
                'created_by' => $reversedBy,
                'approved_by' => $reversedBy,
                'approved_at' => now(),
            ]);

            foreach ($existing as $trace) {
                // Debit and credit swapped - that is the whole of a reversal.
                $line = $reversal->lines()->create([
                    'ledger_id' => $trace->ledger_id,
                    'debit' => $trace->credit,
                    'credit' => $trace->debit,
                    'narration' => "Reverses trace #{$trace->id}",
                ]);

                LedgerTrace::create([
                    'ledger_id' => $line->ledger_id,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                    'source_type' => Voucher::class,
                    'source_id' => $reversal->id,
                    'reference' => $reversal->voucher_no,
                    'posted_on' => $reversal->voucher_date,
                    'narration' => $reversal->narration,

                    // The link that makes this a reversal rather than a second,
                    // unexplained entry that happens to cancel out.
                    'reverses_id' => $trace->id,
                ]);
            }

            return $reversal->fresh(['lines']);
        });
    }

    /**
     * Refuse anything that is not still a draft.
     *
     * The message names what was attempted, because "voucher is approved" on
     * its own does not tell somebody whether their edit, approval or rejection
     * was the thing refused.
     */
    private function assertDraft(Voucher $voucher, string $attempted): void
    {
        if ($voucher->isDraft()) {
            return;
        }

        throw new DomainException(
            "This voucher is {$voucher->status} and cannot be {$attempted}. "
                .($voucher->isApproved()
                    ? 'Its entries are in the ledger - post a reversal instead.'
                    : 'A rejected voucher stays as it is.')
        );
    }

    /**
     * Tidy the lines and reject the ones that are not entries.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function normalise(array $lines): array
    {
        if (count($lines) < 2) {
            throw new DomainException(
                'A voucher needs at least two lines - something must be debited and something credited.'
            );
        }

        $normalised = [];

        foreach ($lines as $index => $line) {
            $debit = $this->money($line['debit'] ?? 0);
            $credit = $this->money($line['credit'] ?? 0);

            $hasDebit = bccomp($debit, '0.00', 2) > 0;
            $hasCredit = bccomp($credit, '0.00', 2) > 0;

            $position = $index + 1;

            if ($hasDebit && $hasCredit) {
                throw new DomainException(
                    "Line {$position} has both a debit and a credit. Split it into two lines: "
                        .'one side each, or the balance means nothing.'
                );
            }

            if (! $hasDebit && ! $hasCredit) {
                throw new DomainException("Line {$position} has neither a debit nor a credit.");
            }

            if (! Ledger::where('id', $line['ledger_id'])->where('is_active', true)->exists()) {
                throw new DomainException(
                    "Line {$position} names a ledger that does not exist or has been retired."
                );
            }

            $normalised[] = [
                'ledger_id' => (int) $line['ledger_id'],
                'debit' => $debit,
                'credit' => $credit,
                'narration' => $line['narration'] ?? null,
            ];
        }

        return $normalised;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function assertBalanced(array $lines): void
    {
        $debits = '0.00';
        $credits = '0.00';

        foreach ($lines as $line) {
            $debits = bcadd($debits, (string) $line['debit'], 2);
            $credits = bcadd($credits, (string) $line['credit'], 2);
        }

        if (bccomp($debits, $credits, 2) !== 0) {
            $difference = bcsub($debits, $credits, 2);

            // The difference is in the message because it is the fastest route
            // to the mistake: a reader who knows it is out by 900 usually knows
            // which line is wrong.
            throw new DomainException(
                "This voucher does not balance: debits {$debits}, credits {$credits}, "
                    ."out by {$difference}."
            );
        }

        if (bccomp($debits, '0.00', 2) === 0) {
            throw new DomainException('A voucher of zero posts nothing.');
        }
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    /**
     * `JV-2026-0007`, per type and year.
     *
     * Derived from the highest existing number rather than a count, so a
     * deleted draft does not hand its number to the next voucher - two
     * documents with one number is a filing problem nobody enjoys.
     */
    private function nextNumber(string $type, string $prefixOverride = ''): string
    {
        $prefix = $prefixOverride ?: match ($type) {
            'payment' => 'PV',
            'receipt' => 'RV',
            default => 'JV',
        };

        $year = now()->year;
        $stem = "{$prefix}-{$year}-";

        $last = Voucher::where('voucher_no', 'like', $stem.'%')
            ->orderByDesc('voucher_no')
            ->value('voucher_no');

        $next = $last ? ((int) substr($last, strlen($stem))) + 1 : 1;

        return $stem.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
