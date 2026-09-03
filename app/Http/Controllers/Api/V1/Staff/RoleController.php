<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\TenantSeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Roles and the permission catalogue (FR-RBAC-1, FR-RBAC-2).
 *
 * WHAT AN ASSOCIATION MAY AND MAY NOT CHANGE
 * ------------------------------------------
 * It may create its own roles and set what they can do. Associations are not
 * alike - one has a cashier who never touches member records, another has two
 * people who both do everything - and the seeded three are a starting point,
 * not a straitjacket.
 *
 * It may NOT do three things, each because the alternative is unrecoverable or
 * meaningless:
 *
 *   1. Delete a seeded role. FR-RBAC-2 requires `superadmin`, `admin` and
 *      `operator` to exist.
 *   2. Edit superadmin's permissions. It holds everything by definition,
 *      including permissions added by a later release; letting it be narrowed
 *      is how an association locks itself out of its own administration.
 *   3. Delete a role somebody still holds. Those accounts would be left with no
 *      role at all - able to sign in and do nothing, with no message saying
 *      why.
 */
class RoleController extends Controller
{
    /**
     * The seeded three. FR-RBAC-2 names them, so they cannot be removed.
     */
    private const SEEDED = ['superadmin', 'admin', 'operator'];

    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get();

        $holders = $this->holderCounts();

        return response()->json([
            'data' => $roles->map(fn (Role $r) => $this->shape($r, $holders[$r->id] ?? 0)),
        ]);
    }

    /**
     * How many accounts hold each role, in one query.
     *
     * Counted off the pivot rather than through `Role::users()`, which resolves
     * its model from the guard's auth provider and throws "Class name must be a
     * valid object or a string" here - the `web` guard in a tenant context does
     * not name one. The pivot is the same fact without the indirection.
     *
     * @return array<int, int>
     */
    private function holderCounts(): array
    {
        return DB::table('model_has_roles')
            ->selectRaw('role_id, COUNT(*) as holders')
            ->groupBy('role_id')
            ->pluck('holders', 'role_id')
            ->all();
    }

    /**
     * Every permission this build knows about, grouped as the seeder groups
     * them - so a role editor can show "Fees" and "Accounting" rather than
     * forty checkboxes in one column.
     */
    public function permissions(): JsonResponse
    {
        $catalogue = TenantSeedService::permissionCatalogue();
        $existing = Permission::pluck('name')->all();

        $data = [];

        foreach ($catalogue as $group => $names) {
            foreach ($names as $name) {
                // Only what actually exists in this tenant. A catalogue entry
                // with no permission row would be a checkbox that saves nothing.
                if (in_array($name, $existing, true)) {
                    $data[] = ['name' => $name, 'group' => $group];
                }
            }
        }

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('roles', 'name')->where('guard_name', 'web'),
            ],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ]);

        $role = DB::transaction(function () use ($validated) {
            $role = Role::create(['name' => $validated['name'], 'guard_name' => 'web']);
            $role->syncPermissions($validated['permissions']);

            return $role;
        });

        return response()->json(['data' => $this->shape($role->fresh(['permissions']))], 201);
    }

    public function update(Request $request, int $role): JsonResponse
    {
        $record = $this->find($role);

        if ($record->name === 'superadmin') {
            throw new ApiException(
                'ROLE_NOT_EDITABLE',
                'Superadmin holds every permission by definition and cannot be narrowed.',
                422,
            );
        }

        $validated = $request->validate([
            // The seeded names are referenced by FR-RBAC-2 and by the seeder
            // itself, so only a role an association made may be renamed.
            'name' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($record->id),
            ],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ]);

        if (isset($validated['name']) && in_array($record->name, self::SEEDED, true)) {
            throw new ApiException(
                'ROLE_NOT_RENAMEABLE',
                'The seeded roles cannot be renamed. Create a role of your own instead.',
                422,
            );
        }

        DB::transaction(function () use ($record, $validated) {
            if (isset($validated['name'])) {
                $record->update(['name' => $validated['name']]);
            }

            if (isset($validated['permissions'])) {
                $record->syncPermissions($validated['permissions']);
            }
        });

        return response()->json(['data' => $this->shape($record->fresh(['permissions']))]);
    }

    public function destroy(int $role): JsonResponse
    {
        $record = $this->find($role);

        if (in_array($record->name, self::SEEDED, true)) {
            throw new ApiException(
                'ROLE_NOT_DELETABLE',
                'The seeded roles are required and cannot be deleted.',
                422,
            );
        }

        $holders = $this->holdersOf($record);

        if ($holders > 0) {
            throw new ApiException(
                'ROLE_IN_USE',
                "{$holders} staff account".($holders === 1 ? ' still holds' : 's still hold')
                .' this role. Move them to another role first.',
                422,
            );
        }

        $record->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** Holders of one role. The same pivot count, for a single role. */
    private function holdersOf(Role $role): int
    {
        return DB::table('model_has_roles')->where('role_id', $role->id)->count();
    }

    private function find(int $id): Role
    {
        $role = Role::with('permissions:id,name')->find($id);

        if (! $role) {
            throw new ApiException('NOT_FOUND', 'No such role.', 404);
        }

        return $role;
    }

    /** @return array<string, mixed> */
    private function shape(Role $role, ?int $holders = null): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->values(),
            'users' => $holders ?? $this->holdersOf($role),

            // So a client can grey out the controls rather than offering an
            // action the server will refuse.
            'is_seeded' => in_array($role->name, self::SEEDED, true),
            'is_editable' => $role->name !== 'superadmin',
        ];
    }
}
