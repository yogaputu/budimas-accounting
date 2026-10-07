<?php

namespace Tests\Feature\Auth;

use App\Models\Access\Role;
use App\Models\Access\RoleAssignment;
use App\Models\User;
use Database\Seeders\Access\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_when_opening_dashboard(): void
    {
        $this->get(route('accounting.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_active_user_can_log_in_and_open_dashboard(): void
    {
        $user = $this->createUserWithRole('viewer');

        $this->withoutVite()
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'correct-password',
            ])
            ->assertRedirect(route('accounting.dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'is_active' => false,
            'password' => Hash::make('correct-password'),
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'correct-password',
        ])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_user_with_dashboard_permission_can_view_dashboard(): void
    {
        $user = $this->createUserWithRole('viewer');

        $this->withoutVite()
            ->actingAs($user)
            ->get(route('accounting.dashboard'))
            ->assertOk()
            ->assertSee($user->name)
            ->assertSee('Beranda Accounting');
    }

    public function test_login_is_rate_limited_after_five_failed_attempts(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'incorrect-password',
            ])->assertSessionHasErrors('email');
        }

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])->assertTooManyRequests();
    }

    public function test_logout_invalidates_the_authenticated_session(): void
    {
        $user = $this->createUserWithRole('viewer');

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    private function createUserWithRole(string $roleCode): User
    {
        $this->seed(AccessControlSeeder::class);

        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
        ]);

        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('code', $roleCode)->firstOrFail()->id,
            'scope_key' => 'global',
            'assigned_at' => now(),
        ]);

        return $user;
    }
}
