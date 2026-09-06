<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Member;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Staff accounts (FR-RBAC-1).
 *
 * WHY THIS EXISTS
 * ---------------
 * Because without it an association cannot be onboarded at all. The seeded
 * demo account was the only way into the staff surface; there was no endpoint
 * to create a second one, so the office manager, the cashier and the accountant
 * had nowhere to sign in from. `users.view/create/edit/delete` have been in the
 * permission catalogue since the first seed and had nothing behind them.
 *
 * THE LOCKOUT GUARD IS THE POINT OF THIS FILE
 * -------------------------------------------
 * Every destructive path here can, if unguarded, leave an association with
 * nobody who can administer it - and there is no support desk to ring. So:
 *
 *   - the last superadmin cannot be deleted, and cannot be moved off the role;
 *   - you cannot delete your own account.
 *
 * Neither is a nicety. Recovering from either would mean someone with database
 * access editing rows by hand, in a system whose whole premise is that each
 * association's data is separate and self-administered.
 *
 * A STAFF EMAIL MAY ALREADY BELONG TO A MEMBER, and that is allowed: a
 * treasurer is usually also a member, and SRS OD-4 has them as two separate
 * records. So this file WARNS and does not refuse. See dualAccountNotice().
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->with('roles:id,name')
            ->when($request->query('q'), fn ($q, $term) => $q->where(function ($inner) use ($term) {
                $inner->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            }))
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $users->map(fn (User $u) => $this->shape($u)),
            'meta' => ['total' => $users->count()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],

            /*
             * Set by whoever creates the account, because there is nothing to
             * email it to them with - SMS and mail are not built. The staff
             * member is told it at the desk and should change it; there is no
             * pretending this is a self-service invitation flow.
             */
            'password' => ['required', 'string', 'min:8', 'max:255'],

            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ]);

        $user = DB::transaction(function () use ($validated, $request) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                // Hashed by the model's cast, as everywhere else.
                'password' => $validated['password'],
            ]);

            $user->assignRole($validated['role']);
            $this->audit($request, $user, 'user.created', null, $this->shape($user->fresh('roles')));

            return $user;
        });

        return response()->json([
            'data' => $this->shape($user->fresh('roles')),
            'meta' => ['notice' => $this->dualAccountNotice($validated['email'])],
        ], 201);
    }

    public function update(Request $request, int $user): JsonResponse
    {
        $record = $this->find($user);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($record->id)],

            // Optional. Absent means "leave it alone", which is what an edit
            // form that does not show the current password has to mean.
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:255'],

            'role' => ['sometimes', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ]);

        $before = $this->shape($record);

        if (array_key_exists('role', $validated) && $validated['role'] !== 'superadmin') {
            $this->guardLastSuperadmin($record, 'moved off the superadmin role');
        }

        DB::transaction(function () use ($record, $validated, $request, $before) {
            $record->fill(array_filter(
                [
                    'name' => $validated['name'] ?? null,
                    'email' => $validated['email'] ?? null,
                    'password' => $validated['password'] ?? null,
                ],
                fn ($v) => $v !== null,
            ))->save();

            if (isset($validated['role'])) {
                // sync, not assign: a staff account holds exactly one role, and
                // assignRole would leave the old one in place beside the new.
                $record->syncRoles([$validated['role']]);
            }

            $this->audit($request, $record, 'user.updated', $before, $this->shape($record->fresh('roles')));
        });

        /*
         * Only when the email MOVED. Repeating the notice every time somebody
         * fixes a spelling on an account that has always collided is noise, and
         * a warning shown for no reason is a warning nobody reads.
         */
        $moved = isset($validated['email']) && $validated['email'] !== $before['email'];

        return response()->json([
            'data' => $this->shape($record->fresh('roles')),
            'meta' => ['notice' => $moved ? $this->dualAccountNotice($validated['email']) : null],
        ]);
    }

    public function destroy(Request $request, int $user): JsonResponse
    {
        $record = $this->find($user);

        if ($record->id === $request->user()?->id) {
            throw new ApiException(
                'CANNOT_DELETE_SELF',
                'You cannot delete the account you are signed in with.',
                422,
            );
        }

        $this->guardLastSuperadmin($record, 'deleted');

        $before = $this->shape($record);

        DB::transaction(function () use ($record, $request, $before) {
            /*
             * The tokens go with the account. Leaving them would let a deleted
             * staff member carry on using the app until their token expired -
             * which for a personal access token is not a short window.
             */
            $record->tokens()->delete();

            $this->audit($request, $record, 'user.deleted', $before, null);
            $record->delete();
        });

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * Refuse anything that would leave the association with no superadmin.
     *
     * There is no support desk to ring and no cross-tenant administrator: an
     * association that loses its last superadmin cannot get it back without
     * someone editing the database by hand.
     */
    private function guardLastSuperadmin(User $user, string $action): void
    {
        if (! $user->hasRole('superadmin')) {
            return;
        }

        $remaining = User::role('superadmin')->where('id', '!=', $user->id)->count();

        if ($remaining === 0) {
            throw new ApiException(
                'LAST_SUPERADMIN',
                "This is the only superadmin, so the account cannot be {$action}. "
                .'Give another account the superadmin role first.',
                422,
            );
        }
    }

    /**
     * "This email is also a member's" - said, not enforced.
     *
     * WHY IT IS NOT A REFUSAL
     * -----------------------
     * Because the collision is usually correct. The treasurer is a member of
     * the association she keeps the books for, and SRS OD-4 gives her two
     * records on purpose: one for the ledger she administers, one for the dues
     * she owes. Refusing the second account would be refusing the ordinary case.
     *
     * WHY IT IS SAID AT ALL
     * ---------------------
     * Because of what happens at sign-in. Both accounts are tried and the
     * PASSWORD picks one, so two different passwords resolve silently and
     * nobody is ever asked anything. One password opening both is genuinely
     * ambiguous, and she is asked which she means - every single time.
     *
     * That is the only decision the person creating the account can make, and
     * this is the only moment they can make it: they are choosing the password
     * on the very screen this notice comes back to. Afterwards it costs an edit.
     */
    private function dualAccountNotice(?string $email): ?string
    {
        if (! $email) {
            return null;
        }

        $member = Member::with('associatorInfo')->where('email', $email)->first();

        if (! $member) {
            return null;
        }

        /*
         * The membership number lives on associators_infos, not on the member -
         * it is assigned by the office after the record exists, so a member can
         * legitimately have none. Named when there is one, because on a register
         * of several hundred a name alone is not always enough to tell who.
         */
        $number = $member->associatorInfo?->membership_no;
        $who = $number ? "{$member->name} ({$number})" : $member->name;

        return "This email already belongs to a member, {$who}. That is fine - one person can hold "
            .'both accounts. Just make sure this password is DIFFERENT from their member password, '
            .'or they will be asked which account they mean every time they sign in.';
    }

    private function find(int $id): User
    {
        $user = User::with('roles:id,name')->find($id);

        if (! $user) {
            throw new ApiException('NOT_FOUND', 'No such staff account.', 404);
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function shape(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,

            // One role per account. The array form is what spatie returns; the
            // first is the only one this API ever sets.
            'role' => $user->roles->first()?->name,

            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function audit(Request $request, User $subject, string $action, ?array $before, ?array $after): void
    {
        AuditLog::create([
            'actor_type' => $request->user()::class,
            'actor_id' => $request->user()->id,
            'subject_type' => User::class,
            'subject_id' => $subject->id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
        ]);
    }
}
