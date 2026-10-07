<?php

namespace App\Http\Controllers;

use App\Http\Requests\Access\UpdateRolePermissionsRequest;
use App\Http\Requests\Access\UpdateUserRolesRequest;
use App\Models\Access\AccessAudit;
use App\Models\Access\Permission;
use App\Models\Access\Role;
use App\Models\Access\RoleAssignment;
use App\Models\User;
use App\RoleCode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccessControlController extends Controller
{
    public function index(): View
    {
        $roles = Role::query()
            ->with('permissions')
            ->orderBy('name')
            ->get();

        $permissionsByModule = Permission::query()
            ->orderBy('module')
            ->orderBy('name')
            ->get()
            ->groupBy('module');

        $users = User::query()
            ->with([
                'roleAssignments' => static fn ($query) => $query
                    ->whereNull('revoked_at')
                    ->where(static fn ($expiryQuery) => $expiryQuery
                        ->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now()))
                    ->with('role'),
            ])
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('access.index', compact('permissionsByModule', 'roles', 'users'));
    }

    public function updateUserRoles(UpdateUserRolesRequest $request, User $user): RedirectResponse
    {
        $isActive = $request->boolean('is_active');

        /** @var list<int> $roleIds */
        $roleIds = collect($request->validated('role_ids', []))
            ->map(static fn (mixed $roleId): int => (int) $roleId)
            ->unique()
            ->values()
            ->all();

        DB::transaction(function () use ($isActive, $request, $roleIds, $user): void {
            $assignments = RoleAssignment::query()
                ->where('user_id', $user->id)
                ->where('scope_key', 'global')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get()
                ->filter(static fn (RoleAssignment $assignment): bool => $assignment->isActive())
                ->values();

            $superAdminRoleId = Role::query()
                ->where('code', RoleCode::SuperAdmin->value)
                ->value('id');

            $isRemovingTargetSuperAdmin = $superAdminRoleId !== null
                && ! in_array($superAdminRoleId, $roleIds, true)
                && $assignments->contains('role_id', $superAdminRoleId);

            $isDeactivatingTargetSuperAdmin = $superAdminRoleId !== null
                && ! $isActive
                && $assignments->contains('role_id', $superAdminRoleId);

            if ($isRemovingTargetSuperAdmin || $isDeactivatingTargetSuperAdmin) {
                $globalSuperAdminCount = RoleAssignment::query()
                    ->where('role_id', $superAdminRoleId)
                    ->where('scope_key', 'global')
                    ->whereNull('revoked_at')
                    ->whereHas('user', static fn ($userQuery) => $userQuery->where('is_active', true))
                    ->where(static fn ($query) => $query
                        ->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now()))
                    ->lockForUpdate()
                    ->count();

                if ($globalSuperAdminCount <= 1) {
                    throw ValidationException::withMessages([
                        'role_ids' => 'Minimal satu Super Admin global harus tetap aktif.',
                    ]);
                }
            }

            $existingRoleIds = $assignments->pluck('role_id');
            $rolesToRevoke = $existingRoleIds->diff($roleIds);

            if ($rolesToRevoke->isNotEmpty()) {
                RoleAssignment::query()
                    ->where('user_id', $user->id)
                    ->where('scope_key', 'global')
                    ->whereNull('revoked_at')
                    ->whereIn('role_id', $rolesToRevoke)
                    ->update(['revoked_at' => now(), 'updated_at' => now()]);
            }

            foreach (collect($roleIds)->diff($existingRoleIds) as $roleId) {
                RoleAssignment::query()->create([
                    'user_id' => $user->id,
                    'role_id' => $roleId,
                    'scope_key' => 'global',
                    'assigned_by' => $request->user()->id,
                    'assigned_at' => now(),
                ]);
            }

            $previousIsActive = $user->is_active;

            $user->forceFill([
                'is_active' => $isActive,
            ])->save();

            AccessAudit::query()->create([
                'actor_id' => $request->user()->id,
                'action' => 'access.user_roles_updated',
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'old_values' => [
                    'global_role_ids' => $existingRoleIds->values()->all(),
                    'is_active' => $previousIsActive,
                ],
                'new_values' => [
                    'global_role_ids' => $roleIds,
                    'is_active' => $isActive,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        });

        return back()->with('status', 'Hak akses pengguna berhasil diperbarui.');
    }

    public function updateRolePermissions(
        UpdateRolePermissionsRequest $request,
        Role $role,
    ): RedirectResponse {
        if ($role->code === RoleCode::SuperAdmin->value) {
            return back()->withErrors([
                'permission_ids' => 'Permission Super Admin dikunci untuk mencegah seluruh sistem kehilangan akses.',
            ]);
        }

        /** @var list<int> $permissionIds */
        $permissionIds = collect($request->validated('permission_ids', []))
            ->map(static fn (mixed $permissionId): int => (int) $permissionId)
            ->unique()
            ->values()
            ->all();

        DB::transaction(function () use ($permissionIds, $request, $role): void {
            $previousPermissionIds = $role->permissions()
                ->pluck('permissions.id')
                ->sort()
                ->values()
                ->all();

            $role->permissions()->sync($permissionIds);

            AccessAudit::query()->create([
                'actor_id' => $request->user()->id,
                'action' => 'access.role_permissions_updated',
                'subject_type' => Role::class,
                'subject_id' => $role->id,
                'old_values' => ['permission_ids' => $previousPermissionIds],
                'new_values' => ['permission_ids' => $permissionIds],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        });

        return back()->with('status', "Permission untuk role {$role->name} berhasil diperbarui.");
    }
}
