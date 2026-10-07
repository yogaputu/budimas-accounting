<?php

namespace Tests\Feature\Accounting;

use App\Models\Access\Role;
use App\Models\Access\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_health_endpoint_is_available_without_login(): void
    {
        $this->getJson('/api/v1/accounting/health')
            ->assertOk()
            ->assertJson([
                'service' => 'budimas-accounting',
                'status' => 'ok',
            ]);
    }

    public function test_returns_seeded_accounting_status_for_authorized_user(): void
    {
        $this->seed();

        $user = User::factory()->create();
        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('code', 'super_admin')->firstOrFail()->id,
            'scope_key' => 'global',
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/accounting/status');

        $response
            ->assertOk()
            ->assertJsonPath('service', 'budimas-accounting')
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database_connection', 'sqlite')
            ->assertJsonPath('source_mode', 'read_only_sync')
            ->assertJsonPath('entities.0.code', 'BMM')
            ->assertJsonPath('entities.0.branch_count', 0)
            ->assertJsonCount(2, 'entities')
            ->assertJsonCount(2, 'sources')
            ->assertJsonFragment([
                'key' => 'erp_postgres',
                'access_mode' => 'read_only',
            ]);
    }
}
