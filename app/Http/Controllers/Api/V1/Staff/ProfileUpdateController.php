<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberProfileUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The queue of changes members have asked the office to make (FR-MEM-8).
 *
 * EVERY REQUEST IS SHOWN AS A BEFORE AND AFTER. An officer approving "mobile:
 * 01712345678" cannot judge it without knowing what the number is now - a
 * digit changed and a number replaced entirely are different decisions, and
 * only one of them is likely to be somebody taking over an account.
 *
 * A DECISION IS FINAL AND KEPT. Approving applies the change; rejecting applies
 * nothing. Either way the request stays, with who decided it and why, because
 * "I asked you to change my address in March" needs an answer.
 */
class ProfileUpdateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $updates = MemberProfileUpdate::query()
            ->with('member:id,name,mobile')
            ->when(
                $request->query('status', MemberProfileUpdate::STATUS_PENDING) !== 'all',
                fn ($q) => $q->where('status', $request->query('status', MemberProfileUpdate::STATUS_PENDING))
            )
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'data' => $updates->getCollection()->map(fn (MemberProfileUpdate $u) => $this->shape($u)),
            'meta' => [
                'current_page' => $updates->currentPage(),
                'total' => $updates->total(),
                'last_page' => $updates->lastPage(),
                'per_page' => $updates->perPage(),
                'pending' => MemberProfileUpdate::pending()->count(),
            ],
        ]);
    }

    /**
     * Approve or reject.
     *
     * A REASON IS REQUIRED TO REJECT, and not to approve. Refusing somebody's
     * request without saying why leaves them to guess and ask again; approving
     * needs no explanation because the result speaks for itself.
     */
    public function decide(Request $request, int $update): JsonResponse
    {
        $record = MemberProfileUpdate::with('member')->findOrFail($update);

        if (! $record->isPending()) {
            throw new ApiException(
                'ALREADY_DECIDED',
                "This request was already {$record->status} on {$record->decided_at?->toDateString()}.",
                422,
            );
        }

        $validated = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:500'],
        ], [
            'reason.required_if' => 'Say why it was refused - the member will ask.',
        ]);

        $approving = $validated['decision'] === 'approve';
        $member = $record->member;
        $before = [];

        DB::transaction(function () use ($record, $member, $approving, $validated, $request, &$before) {
            if ($approving) {
                /*
                 * Filtered against ALLOWED again at the moment of applying, not
                 * only when submitted. A request could have been written when
                 * the list was wider, and what is applied is what matters.
                 */
                $changes = array_intersect_key(
                    $record->changes,
                    array_flip(MemberProfileUpdate::ALLOWED)
                );

                $before = array_intersect_key($member->only(array_keys($changes)), $changes);

                $member->update($changes);
            }

            $record->update([
                'status' => $approving
                    ? MemberProfileUpdate::STATUS_APPROVED
                    : MemberProfileUpdate::STATUS_REJECTED,
                'decision_reason' => $validated['reason'] ?? null,
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
            ]);
        });

        /*
         * Audited against the MEMBER, not the request. In a year, the question
         * is "when did this member's mobile number change and who allowed it",
         * and a log entry filed under a request id nobody remembers does not
         * answer it.
         */
        AuditLog::create([
            'actor_type' => $request->user()::class,
            'actor_id' => $request->user()->id,
            'subject_type' => Member::class,
            'subject_id' => $member->id,
            'action' => $approving ? 'member.profile_update_approved' : 'member.profile_update_rejected',
            'before' => $before,
            'after' => $approving ? $record->changes : [],
            'reason' => $validated['reason'] ?? null,
            'ip' => $request->ip(),
        ]);

        return response()->json(['data' => $this->shape($record->fresh(['member']))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(MemberProfileUpdate $update): array
    {
        $member = $update->member;

        /*
         * Each field as a pair. A screen showing only the proposed value asks
         * an officer to approve a change they cannot see the shape of.
         */
        $fields = [];

        foreach ($update->changes as $field => $proposed) {
            $fields[] = [
                'field' => $field,
                'current' => $member?->{$field},
                'proposed' => $proposed,
            ];
        }

        return [
            'id' => $update->id,
            'member_id' => $update->member_id,
            'member_name' => $member?->name,
            'member_mobile' => $member?->mobile,
            'fields' => $fields,
            'status' => $update->status,
            'decision_reason' => $update->decision_reason,
            'decided_at' => $update->decided_at?->toDateTimeString(),
            'requested_at' => $update->created_at?->toDateTimeString(),
        ];
    }
}
