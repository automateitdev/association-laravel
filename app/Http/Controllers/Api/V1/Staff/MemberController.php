<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\AssociatorInfo;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Member;
use App\Reports\Column;
use App\Reports\ExportsListings;
use App\Reports\MemberProfileRenderer;
use App\Reports\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Member management for staff (FR-MEM-1 … FR-MEM-7).
 */
class MemberController extends Controller
{
    use ExportsListings;

    /**
     * Columns a caller may order by, and where each one actually lives.
     *
     * A whitelist rather than the raw parameter: `?sort=` reaching orderBy
     * unchecked is an injection point, and a column name that merely does not
     * exist is a 500 where a validation error belongs.
     */
    private const SORTABLE = [
        'name' => 'members.name',
        'mobile' => 'members.mobile',
        'status' => 'members.status',
        'membership_no' => 'associators_infos.membership_no',
        'shares' => 'associators_infos.num_or_shares',
        'added' => 'members.created_at',
    ];

    public function index(Request $request): JsonResponse
    {
        $members = $this->listing($request)
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'data' => $members->getCollection()->map(fn (Member $m) => $this->shape($m)),
            'meta' => [
                'current_page' => $members->currentPage(),
                'per_page' => $members->perPage(),
                'total' => $members->total(),
                'last_page' => $members->lastPage(),
            ],
        ]);
    }

    /**
     * The same list, as a file (FR-REP-7).
     *
     * Takes the SAME filters as the screen and deliberately ignores its
     * pagination: a download of page 2 of the members list is not a thing
     * anybody wants. What you filtered is what you get, all of it.
     */
    public function export(Request $request): Response
    {
        $format = $this->exportFormat($request);
        $filters = $this->filters($request);

        $rows = $this->listing($request)
            ->get()
            ->map(function (Member $m) {
                $shaped = $this->shape($m);

                return [
                    'membership_no' => $shaped['membership_no'] ?? '',
                    'name' => $shaped['name'],
                    'mobile' => $shaped['mobile'],
                    'email' => $shaped['email'] ?? '',
                    'status' => $shaped['status'],
                    'shares' => $shaped['shares'],
                    'added' => $m->created_at?->toDateString() ?? '',
                ];
            })
            ->all();

        if (($tooLarge = $this->rejectIfTooLarge($rows)) !== null) {
            return $tooLarge;
        }

        return $this->sendExport(
            new Report(
                title: 'Members',
                association: $this->associationName(),
                columns: [
                    new Column('membership_no', 'Membership no'),
                    new Column('name', 'Name'),
                    new Column('mobile', 'Mobile'),
                    new Column('email', 'Email'),
                    new Column('status', 'Status'),
                    /*
                     * Shares are a COUNT, not money. Formatting them as
                     * currency would put a number in the same shape as an
                     * amount and invite someone to add the two together.
                     */
                    new Column('shares', 'Shares', Column::TYPE_INTEGER),
                    new Column('added', 'Added'),
                ],
                rows: $rows,
                filters: array_filter([
                    'Status' => $filters['status'] === null ? 'All' : ucfirst($filters['status']),
                    'Search' => $filters['q'],
                    'Added' => $this->describePeriod($filters['from'], $filters['to'], 'Any date'),
                ]),
                currency: $this->currency(),
            ),
            $format,
        );
    }

    /**
     * One member's whole record as a PDF (legacy `member-pdf/{id}`).
     *
     * NOT AN EXPORT FORMAT. `/members/export` takes `format=` and produces a
     * listing in one of three; this produces one document in one format,
     * because a profile is a form rather than a grid of rows. See
     * MemberProfileRenderer for why it does not go through Report/Column.
     *
     * TWO PERMISSIONS, matching the listing export rather than the detail
     * screen. Reading a member on screen and taking their record away as a file
     * are different acts: the file carries their NID, both addresses and their
     * nominee's NID, and it outlives the session that produced it. The pairing
     * is the same one every download in this API uses.
     */
    public function profile(int $member, MemberProfileRenderer $renderer): Response
    {
        return $renderer->render($this->find($member), $this->associationName());
    }

    /**
     * The members list as the screen and the download both see it.
     *
     * One builder, so a filter that narrows the screen cannot fail to narrow
     * the file - the same reason the reports were built around a single query.
     */
    private function listing(Request $request): Builder
    {
        $filters = $this->filters($request);
        $sort = $this->sortFrom($request, self::SORTABLE);

        $query = Member::query()
            // Qualified, because the sort below may join another table and an
            // unqualified `name` would then be ambiguous.
            ->select('members.*')
            ->with('associatorInfo:id,member_id,membership_no,num_or_shares')
            ->when($filters['status'], fn ($q, $s) => $q->where('members.status', $s))
            /*
             * The membership number is searchable, and it is the field staff
             * reach for first.
             *
             * It lives on associators_infos rather than on members, so it needs
             * a subquery - the join cannot simply be added here, because this
             * builder is also used with a leftJoin for sorting and joining the
             * same table twice is an error rather than a duplicate.
             */
            ->when($filters['q'], function ($q, $term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('members.name', 'like', "%{$term}%")
                        ->orWhere('members.mobile', 'like', "%{$term}%")
                        ->orWhere('members.email', 'like', "%{$term}%")
                        ->orWhereExists(function ($exists) use ($term) {
                            $exists->selectRaw('1')
                                ->from('associators_infos')
                                ->whereColumn('associators_infos.member_id', 'members.id')
                                ->where('associators_infos.membership_no', 'like', "%{$term}%");
                        });
                });
            })
            /*
             * The date range is on `created_at` - when the office added this
             * member - and NOT on the society join date.
             *
             * join_date exists on associators_infos and would be the more
             * meaningful answer, but nothing populates it yet: neither staff
             * member creation nor the demo seeder sets one. A filter that
             * silently returns nothing because its column is empty is worse
             * than one answering a slightly narrower question.
             */
            ->when($filters['from'], fn ($q, $d) => $q->whereDate('members.created_at', '>=', $d))
            ->when($filters['to'], fn ($q, $d) => $q->whereDate('members.created_at', '<=', $d));

        if ($sort !== null && str_starts_with($sort['column'], 'associators_infos.')) {
            // LEFT, not inner: a member with no society record yet must still
            // appear in the list, ordered last rather than missing entirely.
            $query->leftJoin('associators_infos', 'associators_infos.member_id', '=', 'members.id');
        }

        return $sort === null
            ? $query->orderBy('members.name')
            : $query->orderBy($sort['column'], $sort['direction']);
    }

    /** @return array{status: ?string, q: ?string, from: ?string, to: ?string} */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:active,inactive,suspended'],
            'q' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return [
            'status' => $validated['status'] ?? null,
            'q' => $validated['q'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'max:20', 'unique:members,mobile'],
            'email' => ['nullable', 'email', 'max:255', 'unique:members,email'],
            'father_name' => ['nullable', 'string', 'max:255'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'bcs_batch' => ['nullable', 'string', 'max:255'],
            'joining_date' => ['nullable', 'date'],
            'birth_date' => ['nullable', 'date'],
            'gender' => ['nullable', 'in:male,female,other'],
            'nid' => ['nullable', 'string', 'max:50'],
            'present_address' => ['nullable', 'string'],
            'permanent_address' => ['nullable', 'string'],
        ]);

        // Staff-created members still start inactive. Creating a member and
        // approving them are separate decisions, and collapsing them removes
        // the approval record the association relies on.
        $member = DB::transaction(function () use ($validated, $request) {
            $member = Member::create($validated + [
                'status' => Member::STATUS_INACTIVE,
                'created_by' => $request->user()->id,
            ]);

            /*
             * The society record is created with the member, and empty.
             *
             * This mirrors the association's actual practice, which the legacy
             * code states plainly: `forAdminRegister()` creates the associator
             * row carrying nothing but member_id, and the number is typed in
             * later on a screen labelled "Office use only", beside the approval
             * date. The registration form has no number field at all.
             *
             * Creating the row now rather than on first assignment means the
             * member always has a society record to attach shares and a number
             * to - and no other code has to cope with its absence.
             */
            AssociatorInfo::create(['member_id' => $member->id]);

            return $member;
        });

        $this->audit($request, $member, 'member.created', null, $this->shape($member));

        return response()->json(['data' => $this->shape($member)], 201);
    }

    public function show(int $member): JsonResponse
    {
        return response()->json(['data' => $this->shape($this->find($member), detailed: true)]);
    }

    public function update(Request $request, int $member): JsonResponse
    {
        $record = $this->find($member);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'mobile' => ['sometimes', 'string', 'max:20', 'unique:members,mobile,'.$record->id],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', 'unique:members,email,'.$record->id],
            'father_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'present_address' => ['sometimes', 'nullable', 'string'],
            'permanent_address' => ['sometimes', 'nullable', 'string'],

            /*
             * WHO INTRODUCED THEM, as a link where that is possible.
             *
             * A member cannot introduce themselves, which is the one rule worth
             * enforcing: it is a data-entry slip rather than a decision, and a
             * member whose introducer is themselves reads on the screen as a
             * circle nobody can explain.
             *
             * The name is the fallback for an introducer who is not a member -
             * the association has one such row today - and is ignored by the
             * screen whenever the link is set, so the two cannot disagree.
             */
            'introduced_by_member_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:members,id',

                // `not_in` rather than `different`, which compares two REQUEST
                // fields - and the member's own id arrives in the route, not
                // the body, so `different:id` would never fire.
                'not_in:'.$record->id,
            ],
            'introduced_by_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $before = $this->shape($record);
        $record->update($validated + ['updated_by' => $request->user()->id]);

        $this->audit($request, $record, 'member.updated', $before, $this->shape($record->fresh()));

        return response()->json(['data' => $this->shape($record->fresh())]);
    }

    /**
     * Status transitions (FR-MEM-6). Each is recorded with actor and reason -
     * suspension and rejection are decisions a member will ask about later.
     */
    public function approve(Request $request, int $member): JsonResponse
    {
        return $this->transition($request, $member, Member::STATUS_ACTIVE, 'member.approved', reasonRequired: false);
    }

    public function reject(Request $request, int $member): JsonResponse
    {
        return $this->transition($request, $member, Member::STATUS_INACTIVE, 'member.rejected', reasonRequired: true);
    }

    public function suspend(Request $request, int $member): JsonResponse
    {
        return $this->transition($request, $member, Member::STATUS_SUSPENDED, 'member.suspended', reasonRequired: true);
    }

    public function reinstate(Request $request, int $member): JsonResponse
    {
        // FR-FINE-6: reinstatement does not forgive debt. Accrued fines stay
        // exactly where they are; only a recorded fine adjustment changes them.
        return $this->transition($request, $member, Member::STATUS_ACTIVE, 'member.reinstated', reasonRequired: true);
    }

    /**
     * Assign or correct the society record (FR-MEM-3).
     *
     * Separate from `update` because it is a different act by a different
     * person: `update` corrects the member's own details, this records what the
     * office decided. The legacy system draws the same line - a distinct screen,
     * distinct permission wording, and the label "Office use only".
     *
     * Numbers are typed, not generated. That is deliberate and matches the
     * association's register: COCSOL's 315 live numbers run 1-317 with two gaps
     * (221 and 245), zero-padded to at least two digits. A generator would
     * either refuse to reproduce those gaps or silently reissue a retired
     * number, and the register - not this system - is the authority.
     *
     * `num_or_shares` is deliberately NOT accepted. It is a denormalised total
     * maintained by ShareService from share payments and recomputable from
     * share history (FR-SHR-6); letting staff type over it would put the figure
     * staff read permanently out of step with the ledger it is derived from.
     * The legacy system allowed both, which is why the two disagree there.
     */
    public function assignAssociatorInfo(Request $request, int $member): JsonResponse
    {
        $record = $this->find($member);

        $info = $record->associatorInfo ?? AssociatorInfo::create(['member_id' => $record->id]);

        $validated = $request->validate([
            // Unique within the association, which is all a membership number
            // means - across associations it is meaningless, which is why
            // members live in the tenant database.
            'membership_no' => [
                'required', 'string', 'max:50',
                Rule::unique('associators_infos', 'membership_no')->ignore($info->id),
            ],
            'join_date' => ['sometimes', 'nullable', 'date'],
            'share_no' => ['sometimes', 'nullable', 'string', 'max:50'],
            'bcs_batch' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'designation' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $before = $info->only(array_keys($validated));
        $info->update($validated);

        $this->audit($request, $record, 'member.associator_info_assigned', $before, $info->fresh()->only(array_keys($validated)));

        return response()->json(['data' => $this->shape($record->fresh(), detailed: true)]);
    }

    // ---- internals -----------------------------------------------------

    private function transition(
        Request $request,
        int $member,
        string $status,
        string $action,
        bool $reasonRequired,
    ): JsonResponse {
        $record = $this->find($member);

        $validated = $request->validate([
            'reason' => [$reasonRequired ? 'required' : 'nullable', 'string', 'max:1000'],
        ]);

        $before = $record->status;

        DB::transaction(function () use ($record, $status, $action, $before, $validated, $request) {
            $record->update(['status' => $status, 'updated_by' => $request->user()->id]);

            AuditLog::create([
                'actor_type' => $request->user()::class,
                'actor_id' => $request->user()->id,
                'subject_type' => Member::class,
                'subject_id' => $record->id,
                'action' => $action,
                'before' => ['status' => $before],
                'after' => ['status' => $status],
                'reason' => $validated['reason'] ?? null,
                'ip' => $request->ip(),
            ]);
        });

        return response()->json(['data' => $this->shape($record->fresh())]);
    }

    private function find(int $id): Member
    {
        // `introducedBy.associatorInfo` so the detail shape can name the
        // introducer and their membership number without two more queries.
        return Member::query()->with(['associatorInfo', 'introducedBy.associatorInfo'])->find($id)
            ?? throw ApiException::notFound('Member');
    }

    private function audit(Request $request, Member $member, string $action, ?array $before, ?array $after): void
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

    private function shape(Member $member, bool $detailed = false): array
    {
        $data = [
            'id' => $member->id,
            'name' => $member->name,
            'mobile' => $member->mobile,
            'email' => $member->email,
            'status' => $member->status,
            'membership_no' => $member->associatorInfo?->membership_no,
            'shares' => (int) ($member->associatorInfo?->num_or_shares ?? 0),
        ];

        if ($detailed) {
            $data += [
                // The society record, so the screen can show what the office has
                // assigned and what is still outstanding.
                'join_date' => $member->associatorInfo?->join_date?->toDateString(),
                'share_no' => $member->associatorInfo?->share_no,
                'company' => $member->associatorInfo?->company,
                'designation' => $member->associatorInfo?->designation,

                'father_name' => $member->father_name,
                'mother_name' => $member->mother_name,
                'bcs_batch' => $member->bcs_batch,
                'joining_date' => $member->joining_date?->toDateString(),
                'birth_date' => $member->birth_date?->toDateString(),
                'gender' => $member->gender,
                'nid' => $member->nid,
                'present_address' => $member->present_address,
                'permanent_address' => $member->permanent_address,

                /*
                 * The introducer, resolved. When the link is set the name comes
                 * from that member's own record rather than from a copy taken
                 * when the form was filled in - which is what stops "Md. Riaz
                 * uddin" and "Md. Riaz Uddin" being two people.
                 */
                'introduced_by' => $member->introduced_by_member_id === null
                    ? ($member->introduced_by_name === null ? null : [
                        'member_id' => null,
                        'name' => $member->introduced_by_name,
                        'membership_no' => null,
                    ])
                    : [
                        'member_id' => $member->introduced_by_member_id,
                        'name' => $member->introducedBy?->name,
                        'membership_no' => $member->introducedBy?->associatorInfo?->membership_no,
                    ],

                /*
                 * And the other direction, which three text columns could not
                 * answer at all. A count rather than the list: eleven members
                 * account for a fifth of this register, and the ones who matter
                 * are worth a number on the page before anybody opens a report.
                 */
                'introduced_count' => $member->introduced()->count(),
            ];
        }

        return $data;
    }

    /**
     * The membership register: the office record for every member.
     *
     * The legacy system had a whole screen for this (`admin.associators-info.*`)
     * and it earns its place - the register answers a different question from
     * the member list. "Who is member 114, when did they join, and which batch
     * were they in" is record-keeping; the member list is about who owes what.
     *
     * `num_or_shares` is reported but NOT editable anywhere, here or on the
     * assignment endpoint. It is derived from completed payments and share
     * transfers, and hand-editing it is exactly how the legacy system ended up
     * with six members holding shares nobody had bought (D-19).
     */
    public function associatorInfos(Request $request): JsonResponse
    {
        $rows = $this->registerQuery($request)
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'data' => $rows->getCollection()->map(fn ($r) => $this->shapeRegister($r)),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'total' => $rows->total(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
            ],
        ]);
    }

    public function exportAssociatorInfos(Request $request): Response
    {
        $format = $this->exportFormat($request);

        $rows = $this->registerQuery($request)
            ->get()
            ->map(fn ($r) => $this->shapeRegister($r))
            ->all();

        if (($tooLarge = $this->rejectIfTooLarge($rows)) !== null) {
            return $tooLarge;
        }

        return $this->sendExport(
            new Report(
                title: 'Membership register',
                association: $this->associationName(),
                columns: [
                    new Column('membership_no', 'Member no.'),
                    new Column('name', 'Name'),
                    new Column('join_date', 'Joined'),
                    new Column('share_no', 'Share no.'),
                    new Column('shares', 'Shares', Column::TYPE_INTEGER),
                    new Column('bcs_batch', 'Batch'),
                    new Column('company', 'Company'),
                    new Column('designation', 'Designation'),
                    new Column('status', 'Status'),
                ],
                rows: $rows,
                filters: array_filter(['Member' => $request->query('q')]),
                currency: $this->currency(),
            ),
            $format,
        );
    }

    /** One query feeding both the screen and its download, so they cannot differ. */
    private function registerQuery(Request $request)
    {
        return DB::table('associators_infos as ai')
            ->join('members as m', 'm.id', '=', 'ai.member_id')
            ->when($request->query('q'), function ($q, $term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('m.name', 'like', "%{$term}%")
                        ->orWhere('ai.membership_no', 'like', "%{$term}%");
                });
            })
            ->orderBy('ai.membership_no')
            ->select([
                'ai.id',
                'ai.member_id',
                'ai.membership_no',
                'ai.join_date',
                'ai.share_no',
                'ai.num_or_shares',
                'ai.bcs_batch',
                'ai.company',
                'ai.designation',
                'm.name',
                'm.status',
            ]);
    }

    /** @return array<string, string|int|null> */
    private function shapeRegister(object $r): array
    {
        return [
            'id' => (int) $r->id,
            'member_id' => (int) $r->member_id,
            'membership_no' => $r->membership_no,
            'name' => $r->name,
            'join_date' => $r->join_date,
            'share_no' => $r->share_no ?? '',
            'shares' => (int) $r->num_or_shares,
            'bcs_batch' => $r->bcs_batch ?? '',
            'company' => $r->company ?? '',
            'designation' => $r->designation ?? '',
            'status' => $r->status,
        ];
    }
}
