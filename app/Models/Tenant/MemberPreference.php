<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Support\Districts;
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

    /**
     * Areas of Dhaka, from what members actually chose in the legacy data.
     *
     * NOT A CLOSED LIST - suggestions. The API accepts any string for the two
     * Dhaka projects, because an association's next site will be somewhere
     * nobody has typed yet and a whitelist would have to be edited before
     * anyone could say so. For `other_district` the areas ARE districts, and
     * those are fixed; see Districts.
     *
     * HERE RATHER THAN ON A CONTROLLER, which is where it started. Both the
     * staff screen and the member's own form need it, and a private const on
     * the staff controller cannot be reached by the member's route without
     * either copying it or reaching across - and a copied list is one that
     * disagrees with itself within a release.
     *
     * @var list<string>
     */
    public const DHAKA_AREAS = [
        /*
         * SPELLED AS THE DATA SPELLS THEM, not as the places are usually
         * written. `Basundhora/Purbachal` is how every legacy row that names
         * it is spelled, and `Bashundhara` would have been the better English
         * - but offering a spelling the imported rows do not use splits one
         * place into two values, which is the exact disease the rest of this
         * table was normalised to cure.
         *
         * `Afteb Nagar` is here because a member chose it. It was missing from
         * the first version of this list, which was written from memory rather
         * than from the answers: of the fifteen Dhaka answers in the legacy
         * data it is one of five distinct places, and a suggestion list that
         * omits somewhere people have already asked for is worse than none.
         */
        'Uttara',
        'Mohammadpur',
        'Basundhora/Purbachal',
        'Afteb Nagar',
        'Mirpur',
        'Amin Bazar',
        'Savar',
        'Keraniganj',

        /*
         * LAST, AND NOT A PLACE. The legacy field is a taggable multi-select -
         * a member could type somewhere nobody had listed - and four of the
         * fifteen answers are exactly that, recorded as `Other`. Keeping it
         * means somebody whose area is not here can still answer; dropping it
         * would make the list a lie about what the association will consider.
         */
        'Other',
    ];

    /**
     * Everything a form needs to render the three project panels.
     *
     * One method for both surfaces. The staff screen and the member's own
     * form ask the same question of the same domain, and two payloads built
     * separately are two payloads that drift.
     *
     * @return array<string, mixed>
     */
    public static function formOptions(): array
    {
        return [
            'projects' => self::PROJECTS,
            'budgets' => self::BUDGETS,
            'loan_percentages' => self::LOAN_PERCENTAGES,

            /*
             * Grouped by division, which is how the country is organised and
             * how somebody scans for their own district. Flat and
             * alphabetical puts Bandarban beside Barguna, five hundred
             * kilometres apart.
             */
            'districts' => Districts::BY_DIVISION,
            'dhaka_areas' => self::DHAKA_AREAS,
        ];
    }

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
