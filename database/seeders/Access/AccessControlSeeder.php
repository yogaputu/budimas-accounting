<?php

namespace Database\Seeders\Access;

use App\Models\Access\Permission;
use App\Models\Access\Role;
use App\Models\Access\RoleAssignment;
use App\Models\User;
use App\PermissionCode;
use App\RoleCode;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

final class AccessControlSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $timestamp = now();

        Permission::query()->upsert(
            collect(PermissionCode::cases())
                ->map(static fn (PermissionCode $permission): array => [
                    'code' => $permission->value,
                    'module' => $permission->module(),
                    'name' => $permission->label(),
                    'description' => $permission->description(),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])
                ->all(),
            ['code'],
            ['module', 'name', 'description', 'updated_at'],
        );

        Role::query()->upsert(
            collect(RoleCode::cases())
                ->map(static fn (RoleCode $role): array => [
                    'code' => $role->value,
                    'name' => $role->label(),
                    'description' => $role->description(),
                    'is_system' => true,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])
                ->all(),
            ['code'],
            ['name', 'description', 'is_system', 'updated_at'],
        );

        $permissionsByCode = Permission::query()
            ->pluck('id', 'code');
        $rolesByCode = Role::query()
            ->get()
            ->keyBy('code');

        $allPermissions = $permissionsByCode->values()->all();
        $rolePermissions = [
            RoleCode::SuperAdmin->value => $allPermissions,
            RoleCode::AdminAccounting->value => $permissionsByCode
                ->except(PermissionCode::AccessManage->value)
                ->values()
                ->all(),
            RoleCode::Finance->value => $permissionsByCode
                ->only([
                    PermissionCode::AccountingDashboardView->value,
                    PermissionCode::JournalManage->value,
                    PermissionCode::ReceivablesManage->value,
                    PermissionCode::ReconciliationManage->value,
                    PermissionCode::ReportView->value,
                    PermissionCode::SystemHealthView->value,
                ])
                ->values()
                ->all(),
            RoleCode::Cashier->value => $permissionsByCode
                ->only([
                    PermissionCode::AccountingDashboardView->value,
                    PermissionCode::CashManage->value,
                    PermissionCode::ReceivablesManage->value,
                    PermissionCode::ReportView->value,
                    PermissionCode::SystemHealthView->value,
                ])
                ->values()
                ->all(),
            RoleCode::Auditor->value => $permissionsByCode
                ->only([
                    PermissionCode::AccountingDashboardView->value,
                    PermissionCode::ReportView->value,
                    PermissionCode::SystemHealthView->value,
                ])
                ->values()
                ->all(),
            RoleCode::Viewer->value => $permissionsByCode
                ->only([
                    PermissionCode::AccountingDashboardView->value,
                    PermissionCode::ReportView->value,
                    // A Viewer may inspect local, already-staged snapshots,
                    // but never starts a source sync or changes source data.
                    PermissionCode::SystemHealthView->value,
                ])
                ->values()
                ->all(),
        ];

        foreach ($rolePermissions as $roleCode => $permissionIds) {
            $rolesByCode[$roleCode]->permissions()->syncWithoutDetaching($permissionIds);
        }

        $this->seedLocalSuperAdmin($rolesByCode[RoleCode::SuperAdmin->value]);
    }

    private function seedLocalSuperAdmin(Role $superAdminRole): void
    {
        if (! app()->isLocal()) {
            return;
        }

        $password = config('accounting.local_admin.password');

        if (! is_string($password) || $password === '') {
            return;
        }

        $admin = User::query()->firstOrNew([
            'email' => (string) config('accounting.local_admin.email'),
        ]);

        $admin->fill([
            'name' => (string) config('accounting.local_admin.name'),
            'password' => $password,
            'is_active' => true,
        ]);
        $admin->save();

        RoleAssignment::query()->firstOrCreate(
            [
                'user_id' => $admin->id,
                'role_id' => $superAdminRole->id,
                'scope_key' => 'global',
                'revoked_at' => null,
            ],
            [
                'assigned_at' => now(),
            ],
        );
    }
}
