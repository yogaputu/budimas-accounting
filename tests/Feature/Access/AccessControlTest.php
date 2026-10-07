<?php

namespace Tests\Feature\Access;

use App\Models\Access\Role;
use App\Models\Access\RoleAssignment;
use App\Models\User;
use Database\Seeders\Access\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_is_redirected_from_access_management(): void
    {
        $this->get(route('access.index'))
            ->assertRedirect(route('login'));
    }

    public function test_viewer_cannot_open_access_management(): void
    {
        $viewer = $this->createUserWithRole('viewer');

        $this->actingAs($viewer)
            ->get(route('access.index'))
            ->assertForbidden();
    }

    public function test_super_admin_can_open_access_management(): void
    {
        $superAdmin = $this->createUserWithRole('super_admin');

        $this->withoutVite()
            ->actingAs($superAdmin)
            ->get(route('access.index'))
            ->assertOk()
            ->assertSee('Hak akses');
    }

    public function test_super_admin_can_assign_a_global_role_and_the_change_is_audited(): void
    {
        $superAdmin = $this->createUserWithRole('super_admin');
        $target = User::factory()->create();
        $viewerRole = Role::query()->where('code', 'viewer')->firstOrFail();

        $this->actingAs($superAdmin)
            ->put(route('access.users.roles.update', $target), [
                'is_active' => true,
                'role_ids' => [$viewerRole->id],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('role_assignments', [
            'user_id' => $target->id,
            'role_id' => $viewerRole->id,
            'scope_key' => 'global',
            'revoked_at' => null,
        ]);
        $this->assertDatabaseHas('access_audits', [
            'actor_id' => $superAdmin->id,
            'action' => 'access.user_roles_updated',
            'subject_id' => $target->id,
        ]);
    }

    public function test_final_global_super_admin_cannot_be_removed(): void
    {
        $superAdmin = $this->createUserWithRole('super_admin');
        $superAdminRole = Role::query()->where('code', 'super_admin')->firstOrFail();

        $this->actingAs($superAdmin)
            ->put(route('access.users.roles.update', $superAdmin), [
                'is_active' => false,
            ])
            ->assertSessionHasErrors('role_ids');

        $this->assertDatabaseHas('role_assignments', [
            'user_id' => $superAdmin->id,
            'role_id' => $superAdminRole->id,
            'scope_key' => 'global',
            'revoked_at' => null,
        ]);
    }

    private function createUserWithRole(string $roleCode): User
    {
        $this->seed(AccessControlSeeder::class);

        $user = User::factory()->create();

        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('code', $roleCode)->firstOrFail()->id,
            'scope_key' => 'global',
            'assigned_at' => now(),
        ]);

        return $user;
    }
}
