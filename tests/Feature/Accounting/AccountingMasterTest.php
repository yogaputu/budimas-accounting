<?php

namespace Tests\Feature\Accounting;

use App\Http\Controllers\AccountingPeriodsController;
use App\Http\Controllers\ChartOfAccountsController;
use App\Models\Access\Role;
use App\Models\Access\RoleAssignment;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\LegalEntity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AccountingMasterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $router = $this->app->make('router');

        if ($router->getRoutes()->getByName('accounting.accounts.index') === null) {
            $router->middleware(['web', 'auth', 'active'])->group(function () use ($router): void {
                $router->get('/uji/akun', [ChartOfAccountsController::class, 'index'])
                    ->middleware('permission:journal.manage,global')
                    ->name('accounting.accounts.index');
                $router->get('/uji/akun/tambah', [ChartOfAccountsController::class, 'create'])
                    ->middleware('permission:journal.manage,global')
                    ->name('accounting.accounts.create');
                $router->post('/uji/akun', [ChartOfAccountsController::class, 'store'])
                    ->middleware('permission:journal.manage,global')
                    ->name('accounting.accounts.store');
                $router->get('/uji/akun/{chartOfAccount}/ubah', [ChartOfAccountsController::class, 'edit'])
                    ->middleware('permission:journal.manage,global')
                    ->name('accounting.accounts.edit');
                $router->put('/uji/akun/{chartOfAccount}', [ChartOfAccountsController::class, 'update'])
                    ->middleware('permission:journal.manage,global')
                    ->name('accounting.accounts.update');

                $router->get('/uji/periode', [AccountingPeriodsController::class, 'index'])
                    ->middleware('permission:accounting.period.manage,global')
                    ->name('accounting.periods.index');
                $router->post('/uji/periode', [AccountingPeriodsController::class, 'store'])
                    ->middleware('permission:accounting.period.manage,global')
                    ->name('accounting.periods.store');
                $router->post('/uji/periode/{accountingPeriod}/tutup', [AccountingPeriodsController::class, 'close'])
                    ->middleware('permission:accounting.period.manage,global')
                    ->name('accounting.periods.close');
                $router->post('/uji/periode/{accountingPeriod}/buka-kembali', [AccountingPeriodsController::class, 'reopen'])
                    ->middleware('permission:accounting.period.manage,global')
                    ->name('accounting.periods.reopen');
            });

            $this->app['url']->setRoutes($router->getRoutes());
        }
    }

    public function test_chart_template_is_seeded_per_bmm_and_tmp_and_customer_advance_is_a_liability(): void
    {
        $this->seed();

        $bmm = LegalEntity::query()->where('code', 'BMM')->firstOrFail();
        $tmp = LegalEntity::query()->where('code', 'TMP')->firstOrFail();

        $this->assertGreaterThan(40, ChartOfAccount::query()->where('legal_entity_id', $bmm->id)->count());
        $this->assertGreaterThan(40, ChartOfAccount::query()->where('legal_entity_id', $tmp->id)->count());
        $this->assertDatabaseCount('accounting_periods', 24);

        $this->assertDatabaseHas('chart_of_accounts', [
            'legal_entity_id' => $bmm->id,
            'code' => '2103',
            'name' => 'Uang Muka Pelanggan',
            'category' => 'liability',
            'normal_balance' => 'credit',
        ]);
        $this->assertDatabaseHas('chart_of_accounts', [
            'legal_entity_id' => $tmp->id,
            'code' => '2103',
            'name' => 'Uang Muka Pelanggan',
            'category' => 'liability',
            'normal_balance' => 'credit',
        ]);
    }

    public function test_viewer_cannot_manage_chart_of_accounts(): void
    {
        $this->seed();
        $viewer = $this->createUserWithRole('viewer');

        $this->actingAs($viewer)
            ->get(route('accounting.accounts.index'))
            ->assertForbidden();
    }

    public function test_super_admin_can_open_chart_of_accounts_page(): void
    {
        $this->seed();
        $superAdmin = $this->createUserWithRole('super_admin');

        $this->withoutVite()
            ->actingAs($superAdmin)
            ->get(route('accounting.accounts.index'))
            ->assertOk()
            ->assertSee('Daftar Akun')
            ->assertSee('Uang Muka Pelanggan');
    }

    public function test_authorized_user_can_create_account_but_cannot_use_cross_entity_parent(): void
    {
        $this->seed();
        $superAdmin = $this->createUserWithRole('super_admin');
        $bmm = LegalEntity::query()->where('code', 'BMM')->firstOrFail();
        $tmp = LegalEntity::query()->where('code', 'TMP')->firstOrFail();
        $bmmParent = ChartOfAccount::query()
            ->where('legal_entity_id', $bmm->id)
            ->where('code', '1100')
            ->firstOrFail();

        $this->actingAs($superAdmin)
            ->post(route('accounting.accounts.store'), [
                'legal_entity_id' => $tmp->id,
                'code' => '1199',
                'name' => 'Aset Uji TMP',
                'category' => 'asset',
                'normal_balance' => 'debit',
                'parent_id' => $bmmParent->id,
                'is_postable' => true,
                'is_active' => true,
            ])
            ->assertSessionHasErrors('parent_id');

        $this->actingAs($superAdmin)
            ->post(route('accounting.accounts.store'), [
                'legal_entity_id' => $tmp->id,
                'code' => '1199',
                'name' => 'Aset Uji TMP',
                'category' => 'asset',
                'normal_balance' => 'debit',
                'is_postable' => true,
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('accounting.accounts.index', ['entity' => $tmp->id]));

        $this->assertDatabaseHas('chart_of_accounts', [
            'legal_entity_id' => $tmp->id,
            'code' => '1199',
            'name' => 'Aset Uji TMP',
        ]);
        $createdAccount = ChartOfAccount::query()
            ->where('legal_entity_id', $tmp->id)
            ->where('code', '1199')
            ->firstOrFail();
        $this->assertDatabaseHas('access_audits', [
            'actor_id' => $superAdmin->id,
            'action' => 'accounting.chart_of_account_created',
            'subject_type' => ChartOfAccount::class,
            'subject_id' => $createdAccount->id,
        ]);
    }

    public function test_periods_cannot_overlap_and_cannot_close_with_draft_journal(): void
    {
        $this->seed();
        $superAdmin = $this->createUserWithRole('super_admin');
        $bmm = LegalEntity::query()->where('code', 'BMM')->firstOrFail();

        $this->actingAs($superAdmin)
            ->post(route('accounting.periods.store'), [
                'legal_entity_id' => $bmm->id,
                'code' => '2027-01',
                'starts_on' => '2027-01-01',
                'ends_on' => '2027-01-31',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($superAdmin)
            ->post(route('accounting.periods.store'), [
                'legal_entity_id' => $bmm->id,
                'code' => '2027-01-B',
                'starts_on' => '2027-01-15',
                'ends_on' => '2027-02-15',
            ])
            ->assertSessionHasErrors('starts_on');

        $period = AccountingPeriod::query()
            ->where('legal_entity_id', $bmm->id)
            ->where('code', '2027-01')
            ->firstOrFail();

        JournalEntry::query()->create([
            'legal_entity_id' => $bmm->id,
            'accounting_period_id' => $period->id,
            'entry_no' => 'JV-TEST-0001',
            'entry_date' => '2027-01-15',
            'source_type' => 'manual',
            'status' => 'draft',
            'description' => 'Jurnal uji draft',
            'total_debit' => 1000,
            'total_credit' => 1000,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'accounting_period_id' => $period->id,
            'entry_no' => 'JV-TEST-0001',
            'status' => 'draft',
        ]);
        $this->assertTrue(DB::table('journal_entries')
            ->where('accounting_period_id', $period->id)
            ->whereNotIn('status', ['posted', 'void'])
            ->exists());

        $response = $this->actingAs($superAdmin)
            ->post(route('accounting.periods.close', $period));

        $response->assertSessionHasErrors('period');

        $this->assertTrue($period->fresh()->isOpen());
    }

    public function test_latest_closed_period_can_be_reopened(): void
    {
        $this->seed();
        $superAdmin = $this->createUserWithRole('super_admin');
        $bmm = LegalEntity::query()->where('code', 'BMM')->firstOrFail();
        $period = AccountingPeriod::query()->create([
            'legal_entity_id' => $bmm->id,
            'code' => '2027-01',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-01-31',
        ]);

        $this->actingAs($superAdmin)
            ->post(route('accounting.periods.close', $period))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('accounting_periods', [
            'id' => $period->id,
            'status' => 'closed',
            'closed_by' => $superAdmin->id,
        ]);
        $this->assertDatabaseHas('access_audits', [
            'actor_id' => $superAdmin->id,
            'action' => 'accounting.period_closed',
            'subject_type' => AccountingPeriod::class,
            'subject_id' => $period->id,
        ]);

        $this->actingAs($superAdmin)
            ->post(route('accounting.periods.reopen', $period))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('accounting_periods', [
            'id' => $period->id,
            'status' => 'open',
            'closed_by' => null,
        ]);
        $this->assertDatabaseHas('access_audits', [
            'actor_id' => $superAdmin->id,
            'action' => 'accounting.period_reopened',
            'subject_type' => AccountingPeriod::class,
            'subject_id' => $period->id,
        ]);
    }

    private function createUserWithRole(string $roleCode): User
    {
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
