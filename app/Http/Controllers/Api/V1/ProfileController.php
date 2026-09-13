<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberProfileUpdate;
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
        ]);

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
}
