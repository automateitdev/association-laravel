<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a member wants from the association's housing (legacy `member_choices`).
 *
 * One row per member per project. See the migration for what each column fixes
 * about the legacy version of it.
 */
class MemberPreference extends Model
{
    /**
     * The three projects, and what to call each on a screen.
     *
     * THE KEY IS NOT THE LABEL, which is the whole reason for this map. The
     * legacy writes `other_distict` into the database and prints
     * "Other District Except Dhaka" on the page, and the two have drifted apart
     * in spelling because nothing kept them together.
     *
     * @var array<string, string>
     */
    public const PROJECTS = [
        'dhaka_city' => 'Inside Dhaka city',
        'near_dhaka' => 'Close to Dhaka city',
        'other_district' => 'Another district',
    ];

    /**
     * The budget ranges the form offers, in lakh taka.
     *
     * @var array<string, string>
     */
    public const BUDGETS = [
        '5-15' => 'Tk. 5–15 lakh',
        '15-25' => 'Tk. 15–25 lakh',
        '25-35' => 'Tk. 25–35 lakh',
        '35+' => 'Above Tk. 35 lakh',
    ];

    /** What the form offers for a loan, `No` being 0. */
    public const LOAN_PERCENTAGES = [0, 25, 50, 75];

    protected $fillable = [
        'member_id',
        'project',
        'areas',
        'flat_size_sft',
        'budget',
        'loan_percentage',
        'flats_wanted',
        'introduced_by_member_id',
        'introduced_by_name',
    ];

    protected function casts(): array
    {
        return [
            'areas' => 'array',
            'flat_size_sft' => 'integer',
            'loan_percentage' => 'integer',
            'flats_wanted' => 'integer',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** The member who told them about the project, when that person is one. */
    public function introducedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'introduced_by_member_id');
    }

    /**
     * Whether this row says anything at all.
     *
     * THE QUESTION THE LEGACY COUNT GOT WRONG. `member_choices` writes three
     * rows per member at registration carrying nothing but a project type, and
     * counting rows "where any column is non-null" counted that scaffolding -
     * which is how 24 real answers were first reported as 273. A row is an
     * answer when a member put something in it.
     */
    public function isAnswered(): bool
    {
        return $this->areas !== null && $this->areas !== []
            || $this->flat_size_sft !== null
            || $this->budget !== null
            || $this->loan_percentage !== null
            || $this->flats_wanted !== null
            || $this->introduced_by_member_id !== null
            || $this->introduced_by_name !== null;
    }
}
