<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\Member;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Authentication for both audiences.
 *
 * Staff and members log in through the SAME endpoint, scoped to the resolved
 * association. The response tells the app which role it is holding, and the
 * token carries abilities so a member token cannot reach a staff endpoint even
 * if routing were mis-configured (FR-AUTH-4).
 */
class AuthController extends Controller
{
    /**
     * Sanctum personal access tokens, not cookie/SPA mode - the client is not a
     * browser (ADR-0004).
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ]);

        $this->throttle($request, $credentials['login']);

        // Staff first, then members. An email that exists in both is a staff
        // login; the two are separate accounts by design (SRS OD-4).
        $account = $this->findStaff($credentials['login'])
            ?? $this->findMember($credentials['login']);

        if (! $account || ! Hash::check($credentials['password'], (string) $account->password)) {
            RateLimiter::hit($this->throttleKey($request, $credentials['login']));

            throw ApiException::invalidCredentials();
        }

        RateLimiter::clear($this->throttleKey($request, $credentials['login']));

        if ($account instanceof Member) {
            $this->guardMemberStatus($account);
        }

        $abilities = $this->abilitiesFor($account);

        $token = $account->createToken(
            $credentials['device_name'] ?? 'mobile',
            $abilities
        );

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'role' => $account instanceof Member ? 'member' : $this->staffRole($account),
                'permissions' => $abilities,
                'profile' => $this->profile($account),
            ],
        ]);
    }

    /** Revokes THIS device only, not every session the member has. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['data' => ['revoked' => true]]);
    }

    public function me(Request $request): JsonResponse
    {
        $account = $request->user();

        return response()->json([
            'data' => [
                'role' => $account instanceof Member ? 'member' : $this->staffRole($account),
                'permissions' => $this->abilitiesFor($account),
                'profile' => $this->profile($account),
            ],
        ]);
    }

    // ---- internals -----------------------------------------------------

    private function findStaff(string $login): ?User
    {
        return User::query()->where('email', $login)->first();
    }

    /** Either email or mobile number (FR-AUTH-2). */
    private function findMember(string $login): ?Member
    {
        return Member::query()
            ->where('email', $login)
            ->orWhere('mobile', $login)
            ->first();
    }

    /**
     * Distinct refusals for inactive and suspended (FR-AUTH-3).
     */
    private function guardMemberStatus(Member $member): void
    {
        if ($member->status === Member::STATUS_INACTIVE) {
            throw ApiException::memberInactive();
        }

        if ($member->status === Member::STATUS_SUSPENDED) {
            // Tell them how far behind they are - it is the first thing they
            // will ask the office, and the office will have to look it up.
            $overdue = FeeAssign::query()
                ->where('member_id', $member->id)
                ->outstanding()
                ->count();

            throw ApiException::memberSuspended($overdue);
        }
    }

    /**
     * @return array<string>
     */
    private function abilitiesFor(User|Member $account): array
    {
        if ($account instanceof Member) {
            // Members get a fixed, narrow set. There is no per-member
            // permission model and there should not be one.
            return [
                'member.profile.view', 'member.profile.request-change',
                'member.dues.view', 'member.payments.view', 'member.payments.create',
            ];
        }

        return $account->getAllPermissions()->pluck('name')->all();
    }

    private function staffRole(User $user): string
    {
        return (string) ($user->getRoleNames()->first() ?? 'staff');
    }

    private function profile(User|Member $account): array
    {
        if ($account instanceof Member) {
            $account->loadMissing('associatorInfo');

            return [
                'id' => $account->id,
                'name' => $account->name,
                'mobile' => $account->mobile,
                'email' => $account->email,
                'status' => $account->status,
                'membership_no' => $account->associatorInfo?->membership_no,
                'shares' => (int) ($account->associatorInfo?->num_or_shares ?? 0),
            ];
        }

        return [
            'id' => $account->id,
            'name' => $account->name,
            'email' => $account->email,
        ];
    }

    /**
     * Throttled per identifier AND per IP (FR-AUTH-7). One without the other is
     * either trivially bypassed or trivially abused to lock a member out.
     */
    private function throttle(Request $request, string $login): void
    {
        $key = $this->throttleKey($request, $login);

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'login' => 'Too many attempts. Try again in '
                    .RateLimiter::availableIn($key).' seconds.',
            ])->status(429);
        }
    }

    private function throttleKey(Request $request, string $login): string
    {
        return 'login:'.tenant()?->getKey().':'.mb_strtolower($login).':'.$request->ip();
    }
}
