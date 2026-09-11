<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Member;
use App\Models\Tenant\Nominee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A member's nominees (FR-MEM-9).
 *
 * NOT A PORT. The legacy system captured nominees on the registration form and
 * then offered no way to change them, so a member whose circumstances changed -
 * married, widowed, divorced - was stuck with whatever was written down on the
 * day they joined. There is no old screen to copy, only a requirement.
 *
 * THE ONE RULE WORTH ENFORCING
 * `share_percentage` is what makes several nominees a split rather than a list.
 * The API refuses a change that would take a member's nominees past 100% in
 * total, because a split adding up to 130% is not a split - it is a dispute
 * that surfaces at the worst possible moment, when the member is not around to
 * say what they meant.
 *
 * It deliberately does NOT require the total to reach 100. A member part way
 * through naming three people should be able to save the first two.
 */
class NomineeController extends Controller
{
    public function index(int $member): JsonResponse
    {
        $record = Member::findOrFail($member);

        $nominees = Nominee::where('member_id', $record->id)->orderBy('name')->get();

        return response()->json([
            'data' => $nominees->map(fn (Nominee $n) => $this->shape($n)),
            'meta' => [
                'member_id' => $record->id,
                'member_name' => $record->name,
                'allocated_percentage' => $this->allocated($record->id),
            ],
        ]);
    }

    public function store(Request $request, int $member): JsonResponse
    {
        $record = Member::findOrFail($member);

        $validated = $this->validated($request);

        $this->guardAllocation($record->id, $validated['share_percentage'] ?? null, null);

        $nominee = Nominee::create($validated + ['member_id' => $record->id]);

        $this->audit($request, $nominee, 'nominee.created', [], $validated);

        return response()->json(['data' => $this->shape($nominee)], 201);
    }

    public function update(Request $request, int $nominee): JsonResponse
    {
        $record = Nominee::findOrFail($nominee);

        $validated = $this->validated($request, updating: true);

        if (array_key_exists('share_percentage', $validated)) {
            $this->guardAllocation($record->member_id, $validated['share_percentage'], $record->id);
        }

        $before = $record->only(array_keys($validated));

        $record->update($validated);

        $this->audit($request, $record, 'nominee.updated', $before, $validated);

        return response()->json(['data' => $this->shape($record->fresh())]);
    }

    /**
     * Remove a nominee.
     *
     * A hard delete, unlike most things here. A nominee is a statement of the
     * member's current wishes rather than a financial record - keeping a
     * revoked one around risks it being read as still standing, which is the
     * opposite of what the member asked for. The audit log holds what was
     * removed and by whom.
     */
    public function destroy(Request $request, int $nominee): JsonResponse
    {
        $record = Nominee::findOrFail($nominee);

        $this->audit($request, $record, 'nominee.deleted', $this->shape($record), []);

        $record->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'relation' => ['sometimes', 'nullable', 'string', 'max:255'],

            /*
             * Optional here, universal in the data. All 315 nominees in the
             * association carry a father's and a mother's name, but a nominee
             * being added today should not be refused because the member is
             * standing at the counter without them - the office can fill them
             * in when it has them.
             */
            'father_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mother_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'gender' => ['sometimes', 'nullable', 'in:male,female,other'],

            'birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
            'nid' => ['sometimes', 'nullable', 'string', 'max:50'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],

            // A job and a workplace together, which is how the legacy data
            // reads: "Lecturer, Noakhali Science & Technology University".
            'profession' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'share_percentage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ]);
    }

    /**
     * Refuse a change that would take the member past 100% allocated.
     *
     * `$excluding` is the nominee being edited, whose current share must not be
     * counted against its own replacement.
     */
    private function guardAllocation(int $memberId, mixed $incoming, ?int $excluding): void
    {
        if ($incoming === null) {
            return;
        }

        $others = $this->allocated($memberId, $excluding);
        $total = bcadd($others, number_format((float) $incoming, 2, '.', ''), 2);

        if (bccomp($total, '100.00', 2) === 1) {
            throw new ApiException(
                'NOMINEE_ALLOCATION_EXCEEDED',
                "This would allocate {$total}% of the member's balance. The nominees of one "
                    ."member cannot add up to more than 100%, and {$others}% is already "
                    .'allocated to others.',
                422,
            );
        }
    }

    /** What percentage is already spoken for, as a money-safe string. */
    private function allocated(int $memberId, ?int $excluding = null): string
    {
        $total = '0.00';

        $query = Nominee::where('member_id', $memberId)->whereNotNull('share_percentage');

        if ($excluding !== null) {
            $query->where('id', '<>', $excluding);
        }

        foreach ($query->pluck('share_percentage') as $share) {
            $total = bcadd($total, (string) $share, 2);
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(Request $request, Nominee $nominee, string $action, array $before, array $after): void
    {
        AuditLog::create([
            'actor_type' => $request->user()::class,
            'actor_id' => $request->user()->id,
            'subject_type' => Nominee::class,
            'subject_id' => $nominee->id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
        ]);
    }

    /** @return array<string, mixed> */
    private function shape(Nominee $n): array
    {
        return [
            'id' => $n->id,
            'member_id' => $n->member_id,
            'name' => $n->name,
            'relation' => $n->relation,
            'father_name' => $n->father_name,
            'mother_name' => $n->mother_name,
            'gender' => $n->gender,
            'birth_date' => $n->birth_date?->toDateString(),
            'nid' => $n->nid,
            'mobile' => $n->mobile,
            'address' => $n->address,
            'profession' => $n->profession,

            // A string, like every other figure the app displays, so nothing
            // downstream is tempted to do arithmetic on it.
            'share_percentage' => $n->share_percentage === null ? null : (string) $n->share_percentage,
        ];
    }
}
