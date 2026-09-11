<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberPreference;
use App\Support\Districts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * What a member wants from the association's housing (legacy `member_choices`).
 *
 * COCSOL is a housing cooperative, so this is the answer to the question it
 * exists to ask - and in the legacy data **18 of 315 members ever answered it**.
 * That number is why this is a small screen rather than a large one: it is
 * worth carrying, and it is not worth building a wizard for.
 *
 * ONE ROW PER PROJECT, AND ALL THREE ARE ALWAYS RETURNED. The three projects
 * are a fixed set, so `index` returns three entries whether or not a member has
 * answered any of them - an empty one is an unanswered question, and a screen
 * that only listed the rows that exist could not show a member what they have
 * not been asked. It is the same reasoning as DocumentService listing every
 * slot, filled or not.
 *
 * WHAT `answered` IS FOR. The legacy writes three rows per member at
 * registration carrying nothing but a project type, and the first count of that
 * table reported 273 members as having answered when the real figure was 18.
 * The flag is computed from whether a member actually put something in the row,
 * never from the row existing.
 */
class MemberPreferenceController extends Controller
{
    public function index(int $member): JsonResponse
    {
        $record = Member::findOrFail($member);

        $held = MemberPreference::query()
            ->with('introducedBy:id,name')
            ->where('member_id', $record->id)
            ->get()
            ->keyBy('project');

        $rows = [];

        foreach (array_keys(MemberPreference::PROJECTS) as $project) {
            $rows[] = $this->shape($project, $held->get($project));
        }

        return response()->json([
            'data' => $rows,
            'meta' => [
                'member_id' => $record->id,
                'member_name' => $record->name,

                // How many of the three this member has actually answered -
                // the figure the legacy count got wrong.
                'answered' => $held->filter(fn (MemberPreference $p) => $p->isAnswered())->count(),
            ],
        ]);
    }

    /**
     * Record one project's answer.
     *
     * A PUT PER PROJECT rather than a POST, because a member has exactly one
     * answer per project and the question is always there to be answered. There
     * is nothing to create and nothing to list twice.
     *
     * AN EMPTY ANSWER DELETES THE ROW. Clearing every field is a member saying
     * they do not want this project, and leaving an all-null row behind would
     * recreate precisely the scaffolding that made the legacy table impossible
     * to count.
     */
    public function update(Request $request, int $member, string $project): JsonResponse
    {
        $record = Member::findOrFail($member);

        abort_unless(array_key_exists($project, MemberPreference::PROJECTS), 404);

        $validated = $this->validated($request, $project);

        $existing = MemberPreference::query()
            ->where('member_id', $record->id)
            ->where('project', $project)
            ->first();

        $before = $existing?->only(array_keys($validated)) ?? [];

        $preference = new MemberPreference($validated + [
            'member_id' => $record->id,
            'project' => $project,
        ]);

        if (! $preference->isAnswered()) {
            $existing?->delete();

            $this->audit($request, $record, 'member.preference_cleared', $before, []);

            return response()->json(['data' => $this->shape($project, null)]);
        }

        $saved = MemberPreference::updateOrCreate(
            ['member_id' => $record->id, 'project' => $project],
            $validated,
        );

        $this->audit($request, $record, 'member.preference_recorded', $before, $validated);

        return response()->json([
            'data' => $this->shape($project, $saved->fresh('introducedBy')),
        ]);
    }

    /** The districts and areas a client can offer, so nothing is typed free-hand. */
    public function options(): JsonResponse
    {
        return response()->json([
            'data' => [
                'projects' => MemberPreference::PROJECTS,
                'budgets' => MemberPreference::BUDGETS,
                'loan_percentages' => MemberPreference::LOAN_PERCENTAGES,

                /*
                 * Grouped by division, which is how the country is organised
                 * and how somebody scans for their own district. Flat and
                 * alphabetical puts Bandarban beside Barguna, five hundred
                 * kilometres apart.
                 */
                'districts' => Districts::BY_DIVISION,

                /*
                 * Areas of Dhaka, from what members actually chose. NOT a
                 * closed list - the API accepts any string for the two Dhaka
                 * projects, because an association's next site will be
                 * somewhere nobody has typed yet and a whitelist would have to
                 * be edited before anyone could say so.
                 */
                'dhaka_areas' => self::DHAKA_AREAS,
            ],
        ]);
    }

    /**
     * What members chose in the legacy data, as suggestions.
     *
     * @var list<string>
     */
    private const DHAKA_AREAS = [
        'Uttara',
        'Mirpur',
        'Mohammadpur',
        'Basundhara/Purbachal',
        'Amin Bazar',
        'Savar',
        'Keraniganj',
        'Other',
    ];

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, string $project): array
    {
        $rules = [
            'areas' => ['sometimes', 'nullable', 'array', 'max:10'],
            'flat_size_sft' => ['sometimes', 'nullable', 'integer', 'min:100', 'max:20000'],
            'budget' => ['sometimes', 'nullable', Rule::in(array_keys(MemberPreference::BUDGETS))],
            'loan_percentage' => ['sometimes', 'nullable', Rule::in(MemberPreference::LOAN_PERCENTAGES)],
            'flats_wanted' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:20'],
            'introduced_by_member_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('members', 'id'),
            ],
            'introduced_by_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];

        /*
         * A DISTRICT IS CHOSEN; AN AREA IS TYPED.
         *
         * For `other_district` the areas ARE districts, and there are 64 of
         * them that do not change - so a value outside the list is a mistake
         * worth refusing at the door. For the two Dhaka projects the areas are
         * neighbourhoods, and the association's next site will be somewhere
         * nobody has typed yet; a whitelist there would have to be edited
         * before a member could say where they want to live.
         *
         * This is exactly what the sweep asked for: free text is right for an
         * address line and wrong for a field meant to be grouped by.
         */
        $rules['areas.*'] = $project === 'other_district'
            ? ['string', Rule::in(Districts::all())]
            : ['string', 'max:100'];

        return $request->validate($rules);
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(string $project, ?MemberPreference $preference): array
    {
        return [
            'project' => $project,
            'project_label' => MemberPreference::PROJECTS[$project],

            // Whether the member has said anything, NOT whether a row exists.
            'answered' => $preference?->isAnswered() ?? false,

            'areas' => $preference?->areas ?? [],
            'flat_size_sft' => $preference?->flat_size_sft,
            'budget' => $preference?->budget,
            'budget_label' => $preference?->budget === null
                ? null
                : MemberPreference::BUDGETS[$preference->budget],
            'loan_percentage' => $preference?->loan_percentage,
            'flats_wanted' => $preference?->flats_wanted,

            /*
             * Resolved through the link when there is one, so the name comes
             * from that member's own record rather than a copy taken when the
             * form was filled in - the same rule as the member's own referee,
             * and what stops one person becoming two.
             */
            'introduced_by_member_id' => $preference?->introduced_by_member_id,
            'introduced_by_name' => $preference?->introduced_by_member_id !== null
                ? $preference?->introducedBy?->name
                : $preference?->introduced_by_name,
        ];
    }

    private function audit(Request $request, Member $member, string $action, array $before, array $after): void
    {
        AuditLog::create([
            'actor_type' => $request->user()::class,
            'actor_id' => $request->user()->id,
            'subject_type' => Member::class,
            'subject_id' => $member->id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
        ]);
    }
}
