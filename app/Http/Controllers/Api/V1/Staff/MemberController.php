<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Member management for staff (FR-MEM-1 … FR-MEM-7).
 */
class MemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $members = Member::query()
            ->with('associatorInfo:id,member_id,membership_no,num_or_shares')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('q'), function ($q, $term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', "%{$term}%")
                        ->orWhere('mobile', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->orderBy('name')
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
        $member = Member::create($validated + [
            'status' => Member::STATUS_INACTIVE,
            'created_by' => $request->user()->id,
        ]);

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
        return Member::query()->with('associatorInfo')->find($id)
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
                'father_name' => $member->father_name,
                'mother_name' => $member->mother_name,
                'bcs_batch' => $member->bcs_batch,
                'joining_date' => $member->joining_date?->toDateString(),
                'birth_date' => $member->birth_date?->toDateString(),
                'gender' => $member->gender,
                'nid' => $member->nid,
                'present_address' => $member->present_address,
                'permanent_address' => $member->permanent_address,
            ];
        }

        return $data;
    }
}
