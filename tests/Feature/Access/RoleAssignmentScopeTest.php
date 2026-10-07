<?php

namespace Tests\Feature\Access;

use App\Models\Access\Role;
use App\Models\Access\RoleAssignment;
use App\Models\Accounting\Branch;
use App\Models\Accounting\LegalEntity;
use App\Models\User;
use App\PermissionCode;
use Database\Seeders\Access\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RoleAssignmentScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_permission_requires_the_exact_branch_and_its_actual_entity(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->for($branch->legalEntity)->create();
        $otherEntity = LegalEntity::factory()->create();
        $actor = $this->branchFinanceUser($branch);

        $this->assertTrue($actor->hasPermission(PermissionCode::JournalManage->value, $branch->legal_entity_id, $branch->id));
        $this->assertTrue($actor->hasPermission(PermissionCode::JournalManage->value, null, $branch->id));
        $this->assertFalse($actor->hasPermission(PermissionCode::JournalManage->value, $branch->legal_entity_id, $otherBranch->id));
        $this->assertFalse($actor->hasPermission(PermissionCode::JournalManage->value, $branch->legal_entity_id));
        $this->assertFalse($actor->hasPermission(PermissionCode::JournalManage->value, $otherEntity->id, $branch->id));
        $this->assertFalse($actor->hasPermission(PermissionCode::JournalManage->value));
    }

    public function test_branch_assignment_without_an_entity_uses_the_actual_branch_owner(): void
    {
        $branch = Branch::factory()->create();
        $assignment = RoleAssignment::factory()->create([
            'scope_key' => 'branch',
            'legal_entity_id' => null,
            'branch_id' => $branch->id,
        ]);

        $this->assertTrue($assignment->allowsScope($branch->legal_entity_id, $branch->id));
        $this->assertFalse($assignment->allowsScope($branch->legal_entity_id));
    }

    #[DataProvider('entityScopeKeys')]
    public function test_entity_scope_aliases_preserve_access_to_their_entity(string $scopeKey): void
    {
        $branch = Branch::factory()->create();
        $otherEntity = LegalEntity::factory()->create();
        $assignment = RoleAssignment::factory()->create([
            'scope_key' => $scopeKey,
            'legal_entity_id' => $branch->legal_entity_id,
        ]);

        $this->assertTrue($assignment->allowsScope($branch->legal_entity_id));
        $this->assertTrue($assignment->allowsScope($branch->legal_entity_id, $branch->id));
        $this->assertFalse($assignment->allowsScope($otherEntity->id));
        $this->assertFalse($assignment->allowsScope());
    }

    public function test_global_scope_remains_available_until_revoked_or_expired(): void
    {
        $this->freezeTime();
        $assignment = RoleAssignment::factory()->create();

        $this->assertTrue($assignment->allowsScope());

        $assignment->revoked_at = now();
        $this->assertFalse($assignment->allowsScope());

        $assignment->revoked_at = null;
        $assignment->expires_at = now()->subMinute();
        $this->assertFalse($assignment->allowsScope());
    }

    public function test_unknown_scope_does_not_grant_entity_or_branch_access(): void
    {
        $branch = Branch::factory()->create();
        $assignment = RoleAssignment::factory()->create([
            'scope_key' => 'invalid',
            'legal_entity_id' => $branch->legal_entity_id,
            'branch_id' => $branch->id,
        ]);

        $this->assertFalse($assignment->allowsScope($branch->legal_entity_id, $branch->id));
    }

    #[DataProvider('entityWideRoutes')]
    public function test_branch_finance_user_cannot_open_entity_wide_journals_or_reports(string $routeName): void
    {
        $branch = Branch::factory()->create();
        $actor = $this->branchFinanceUser($branch);

        $this->withoutVite()
            ->actingAs($actor)
            ->get(route($routeName, ['legal_entity_id' => $branch->legal_entity_id]))
            ->assertForbidden();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function entityScopeKeys(): array
    {
        return [
            'entity' => ['entity'],
            'legacy alias' => ['legal_entity'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function entityWideRoutes(): array
    {
        return [
            'journal list' => ['accounting.journals.index'],
            'journal creation form' => ['accounting.journals.create'],
            'trial balance' => ['accounting.reports.trial-balance'],
            'general ledger' => ['accounting.reports.general-ledger'],
            'cash and bank report' => ['accounting.reports.cash-bank'],
        ];
    }

    private function branchFinanceUser(Branch $branch): User
    {
        $this->seed(AccessControlSeeder::class);
        $actor = User::factory()->create();

        RoleAssignment::factory()->for($actor, 'user')->create([
            'role_id' => Role::query()->where('code', 'finance')->firstOrFail()->id,
            'scope_key' => 'branch',
            'legal_entity_id' => $branch->legal_entity_id,
            'branch_id' => $branch->id,
        ]);

        return $actor;
    }
}
