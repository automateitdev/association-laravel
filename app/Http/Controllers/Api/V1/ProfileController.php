<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberPreference;
use App\Models\Tenant\MemberProfileUpdate;
use App\Support\Districts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A member asking the office to change their details (FR-MEM-8).
 *
 * NOT AN EDIT. Nothing here changes the member's record. It files a request
 * that the office decides on, because these fields are how the association
 * identifies somebody at the counter and how it reaches them - a member who
 * could change their own mobile number unilaterally could also change it to
 * somebody else's, and nothing would record that the old one existed.
 *
 * ONE PENDING REQUEST AT A TIME. Two open requests for the same member let an
 * officer approve them in either order and get different results, which is a
 * race decided by whoever happens to click first.
 */
class ProfileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $member = $this->member($request);

        return response()->json([
            'data' => MemberProfileUpdate::query()
                ->where('member_id', $member->id)
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (MemberProfileUpdate $u) => [
                    'id' => $u->id,
                    'changes' => $u->changes,
                    'status' => $u->status,
                    'decision_reason' => $u->decision_reason,
                    'requested_at' => $u->created_at?->toDateTimeString(),
                    'decided_at' => $u->decided_at?->toDateTimeString(),
                ]),

            // What they may ask to change, fetched rather than hard-coded in
            // the app, so widening the list does not need a release (FR-APP-1).
            'meta' => ['editable_fields' => MemberProfileUpdate::ALLOWED],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $member = $this->member($request);

        if (MemberProfileUpdate::pending()->where('member_id', $member->id)->exists()) {
            throw new ApiException(
                'PROFILE_UPDATE_PENDING',
                'You already have a change waiting for the office. It has to be decided before you can ask for another.',
                409,
            );
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'father_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mother_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'spouse_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'nullable', 'in:male,female,other'],

            /*
             * Unique among members, ignoring this one. A request that would
             * collide is refused now rather than at approval - the member can
             * fix a typo today, where an officer three days later can only
             * refuse it.
             */
            'mobile' => ['sometimes', 'string', 'max:20', Rule::unique('members', 'mobile')->ignore($member->id)],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('members', 'email')->ignore($member->id)],

            /*
             * With the mobile, because they change together: a member who moves
             * abroad gets a new number AND a new country for it, and a queue
             * that accepts one without the other files a request that makes the
             * record worse than it was.
             */
            'country_code' => ['sometimes', 'string', 'size:2', 'alpha'],

            'nid' => ['sometimes', 'nullable', 'string', 'max:50'],
            'present_address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'permanent_address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'office_address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'emergency_contact' => ['sometimes', 'nullable', 'string', 'max:20'],

            /*
             * THE CADRE SERVICE RECORD - the legacy form's first tab asks for
             * all three, and none of them could be corrected here until now.
             *
             * `joining_date` is the date they joined the SERVICE, not the
             * association: the legacy form labels it under "BCS Batch & Cadre"
             * and the association's own join date is a different thing it
             * records itself.
             */
            'bcs_batch' => ['sometimes', 'nullable', 'string', 'max:50'],
            /*
              * An INTEGER, as it is in both schemas - legacy `cader_id` is
              * `int` and its form marks the field numeric. Validated as one
              * here so a typed-in letter is refused with something a member
              * can act on, rather than reaching MySQL and coming back as
              * "Incorrect integer value" in a 500.
              */
            'cadre_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'joining_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],

            /*
             * THE REFERENCE - who vouched for this applicant.
             *
             * `exists` on the member link, so a request naming a member number
             * that is not there is refused now rather than at approval, where
             * an officer can only turn it down. NOT `unique`-style validation
             * on the name: an introducer who is not a member is exactly what
             * `introduced_by_name` is for.
             */
            'introduced_by_member_id' => [
                'sometimes', 'nullable', 'integer', 'exists:members,id',
            ],
            'introduced_by_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'introduced_by_mobile' => ['sometimes', 'nullable', 'string', 'max:20'],

            /*
             * THE NOMINEE, in the same request and the same pending row.
             *
             * The legacy carries `name` and `nominee_name` side by side in
             * `member_profile_updates` and the office decides them together,
             * which is the right shape for what is being decided: changing
             * your nominee is one act. Two queue entries an officer could
             * approve separately would let a nominee's name through while
             * their NID was refused.
             *
             * Sent nested for the app's sake - the form has a section for it -
             * and flattened to `nominee_*` before it is stored, so `changes`
             * stays a flat map the staff screen and the applier already
             * understand.
             */
            'nominee' => ['sometimes', 'array'],
            'nominee.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nominee.relation' => ['sometimes', 'nullable', 'string', 'max:100'],
            'nominee.father_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nominee.mother_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nominee.gender' => ['sometimes', 'nullable', 'in:male,female,other'],
            'nominee.birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
            'nominee.nid' => ['sometimes', 'nullable', 'string', 'max:50'],
            'nominee.mobile' => ['sometimes', 'nullable', 'string', 'max:20'],
            'nominee.country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha'],
            'nominee.address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'nominee.profession' => ['sometimes', 'nullable', 'string', 'max:2000'],

            /*
             * THE HOUSING PREFERENCES - the legacy form's third tab, three
             * projects side by side.
             *
             * Keyed by project so the app sends what it renders, and flattened
             * to `preference_<project>.<field>` before storage. The rules are
             * the same ones the staff endpoint applies, because it is the same
             * question being answered by a different person.
             */
            'preferences' => ['sometimes', 'array'],
            'preferences.*.areas' => ['sometimes', 'nullable', 'array', 'max:10'],
            'preferences.*.flat_size_sft' => [
                'sometimes', 'nullable', 'integer', 'min:100', 'max:20000',
            ],
            'preferences.*.budget' => [
                'sometimes', 'nullable', Rule::in(array_keys(MemberPreference::BUDGETS)),
            ],
            'preferences.*.loan_percentage' => [
                'sometimes', 'nullable', Rule::in(MemberPreference::LOAN_PERCENTAGES),
            ],
            'preferences.*.flats_wanted' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:20'],
            'preferences.*.introduced_by_member_id' => [
                'sometimes', 'nullable', 'integer', 'exists:members,id',
            ],
            'preferences.*.introduced_by_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        /*
         * A DISTRICT IS CHOSEN; AN AREA IS TYPED, and the rule differs by
         * project - so it cannot be expressed in the array above, which has
         * one wildcard for all three.
         *
         * For `other_district` the areas ARE districts: 64 of them, fixed, so
         * a value outside the list is a mistake worth refusing at the door.
         * For the two Dhaka projects they are neighbourhoods, and the
         * association's next site will be somewhere nobody has typed yet.
         * The staff endpoint splits it the same way.
         */
        foreach ($request->input('preferences', []) as $project => $answer) {
            if (! array_key_exists($project, MemberPreference::PROJECTS)) {
                throw new ApiException(
                    'UNKNOWN_PROJECT',
                    "There is no project called [{$project}].",
                    422,
                );
            }

            foreach ($answer['areas'] ?? [] as $area) {
                $valid = $project === 'other_district'
                    ? in_array($area, Districts::all(), true)
                    : is_string($area) && $area !== '' && mb_strlen($area) <= 100;

                if (! $valid) {
                    throw new ApiException(
                        'UNKNOWN_AREA',
                        $project === 'other_district'
                            ? "[{$area}] is not a district."
                            : 'An area name is required and must be under 100 characters.',
                        422,
                    );
                }
            }
        }

        /*
         * A member cannot introduce themselves.
         *
         * Not a hypothetical: the field is a member number typed by hand, and
         * one's own is the number most readily to hand. It would also make
         * `introducedBy` a self-referencing row that reads as a cycle to
         * anything walking the relation.
         */
        if (($validated['introduced_by_member_id'] ?? null) === $member->id) {
            throw new ApiException(
                'SELF_INTRODUCTION',
                'You cannot be your own introducer.',
                422,
            );
        }

        /*
         * Only what actually differs. A form that posts every field would
         * otherwise file a request to change a name to the name it already is,
         * and an officer would have to read it to discover there is nothing in
         * it.
         */
        $nominee = $validated['nominee'] ?? [];
        unset($validated['nominee']);

        $preferencesInput = $validated['preferences'] ?? [];
        unset($validated['preferences']);

        $changes = array_filter(
            $validated,
            fn ($value, $field) => (string) $value !== (string) $member->{$field},
            ARRAY_FILTER_USE_BOTH
        );

        /*
         * The nominee's half, diffed against the one already on file.
         *
         * THE FIRST NOMINEE, and only the first. The legacy member form has
         * exactly one - `nominee_info`, singular, against a singular
         * `$member->nominee` - while this schema lets STAFF record several.
         * So the member's own form maintains theirs and leaves any others
         * alone, rather than a screen that silently edits whichever row came
         * back first.
         *
         * A member with no nominee yet diffs against nothing, so every field
         * they fill in counts as a change - which is what "add a nominee"
         * has to mean.
         */
        $existing = $member->nominees()->orderBy('id')->first();

        foreach ($nominee as $field => $value) {
            if ((string) $value !== (string) ($existing?->{$field} ?? '')) {
                $changes[MemberProfileUpdate::NOMINEE_PREFIX.$field] = $value;
            }
        }

        /*
         * The preferences, diffed per project against the row on file.
         *
         * `areas` is a LIST, so it is compared as one rather than cast to a
         * string: "Uttara, Mirpur" and "Mirpur, Uttara" are the same answer
         * and a string comparison would file a change for reordering them.
         */
        $held = MemberPreference::query()
            ->where('member_id', $member->id)
            ->get()
            ->keyBy('project');

        foreach ($preferencesInput as $project => $answer) {
            $row = $held->get($project);

            foreach ($answer as $field => $value) {
                $before = $row?->{$field};

                $same = $field === 'areas'
                    ? $this->sameAreas($before ?? [], $value ?? [])
                    : (string) $value === (string) ($before ?? '');

                if (! $same) {
                    $changes[MemberProfileUpdate::preferenceKey($project, $field)] = $value;
                }
            }
        }

        /*
         * A NOMINEE CANNOT BE CREATED WITHOUT A NAME, and this is where that
         * is said - not at approval.
         *
         * `nominees.name` is NOT NULL. A member with no nominee yet who fills
         * in a relation and a mobile and nothing else would file a request an
         * officer reads, believes, and approves - and the insert then fails
         * with a database error, on the officer's screen, about the member's
         * form. The member is told nothing and the queue entry is left
         * half-decided.
         *
         * Only when there is no nominee yet: a member who already has one and
         * is correcting a single field is not naming anybody again.
         */
        $addingNominee = $existing === null
            && MemberProfileUpdate::nomineeChanges($changes) !== [];

        if ($addingNominee && trim((string) ($nominee['name'] ?? '')) === '') {
            throw new ApiException(
                'NOMINEE_NAME_REQUIRED',
                'A nominee needs a name.',
                422,
            );
        }

        if ($changes === []) {
            throw new ApiException(
                'NOTHING_TO_CHANGE',
                'Those are the details already on file.',
                422,
            );
        }

        $update = MemberProfileUpdate::create([
            'member_id' => $member->id,
            'changes' => $changes,
            'status' => MemberProfileUpdate::STATUS_PENDING,
        ]);

        return response()->json([
            'data' => [
                'id' => $update->id,
                'changes' => $update->changes,
                'status' => $update->status,
                'requested_at' => $update->created_at->toDateTimeString(),
            ],
        ], 201);
    }

    private function member(Request $request): Member
    {
        $account = $request->user();

        if (! $account instanceof Member) {
            throw new ApiException('MEMBER_ONLY', 'This is a member endpoint.', 403);
        }

        return $account;
    }

    /**
     * What the housing-preference section renders from.
     *
     * The same payload the staff screen gets, from the same place on the
     * model - two lists of budgets built separately are two lists that
     * disagree within a release.
     */
    public function preferenceOptions(): JsonResponse
    {
        return response()->json(['data' => MemberPreference::formOptions()]);
    }

    /**
     * Two area lists holding the same places, in any order.
     *
     * Order is not an answer. A member who reordered "Uttara, Mirpur" into
     * "Mirpur, Uttara" has changed nothing, and filing that as a change gives
     * an officer a request to read with nothing in it.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    private function sameAreas(array $before, array $after): bool
    {
        sort($before);
        sort($after);

        return $before === $after;
    }
}
