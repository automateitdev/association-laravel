<?php

declare(strict_types=1);

namespace App\Services\Migration;

/**
 * The reconciliation report FR-MIG-2 requires, and the argument for the whole
 * migration being trustworthy.
 *
 * Its job is not to say the script finished. It is to put the legacy's numbers
 * and the tenant's numbers in the same table so a person can see that the money
 * did not move, and to name the one row that is expected to differ. A migration
 * that reports "success" and nothing else is asking to be believed; this one
 * shows its working.
 */
class MigrationReport
{
    /** @var array<string, string> */
    public array $before = [];

    /** @var array<string, string> */
    public array $after = [];

    /** @var array<string, int> */
    public array $loaded = [];

    /** @var array<int, string> */
    public array $notes = [];

    /**
     * Rows where the legacy and the tenant disagree.
     *
     * Compared NUMERICALLY where both sides are numbers - `8460000` and
     * `8460000.00` are the same amount of money, and a string comparison would
     * report every money row as a discrepancy and bury the real one.
     *
     * @return array<int, array{measure: string, before: string, after: string}>
     */
    public function discrepancies(): array
    {
        $out = [];

        foreach ($this->before as $measure => $before) {
            $after = $this->after[$measure] ?? '(missing)';

            $same = is_numeric($before) && is_numeric($after)
                ? abs((float) $before - (float) $after) < 0.005
                : $before === $after;

            if (! $same) {
                $out[] = ['measure' => $measure, 'before' => $before, 'after' => $after];
            }
        }

        return $out;
    }
}
