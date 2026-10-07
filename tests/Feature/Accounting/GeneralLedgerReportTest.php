<?php

namespace Tests\Feature\Accounting;

use App\Models\Access\Role;
use App\Models\Access\RoleAssignment;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\Accounting\LegalEntity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GeneralLedgerReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_entity_scoped_report_user_only_sees_posted_lines_for_the_selected_account(): void
    {
        $this->seed();
        $bmm = LegalEntity::query()->where('code', 'BMM')->firstOrFail();
        $period = AccountingPeriod::query()
            ->where('legal_entity_id', $bmm->id)
            ->orderBy('starts_on')
            ->firstOrFail();
        $otherPeriod = AccountingPeriod::query()
            ->where('legal_entity_id', $bmm->id)
            ->whereKeyNot($period->id)
            ->orderBy('starts_on')
            ->firstOrFail();
        $cash = $this->account($bmm, '1101');
        $revenue = $this->account($bmm, '4200');
        $expense = ChartOfAccount::query()
            ->where('legal_entity_id', $bmm->id)
            ->where('category', 'expense')
            ->where('is_postable', true)
            ->firstOrFail();
        $viewer = $this->createScopedReportUser($bmm);

        $entryDate = $period->starts_on->copy()->addDays(4)->toDateString();
        $this->createPostedEntry($bmm, $period, 'JV-GL-0001', $entryDate, 'Penerimaan piutang', [
            ['account_id' => $cash->id, 'debit' => '1000.00', 'credit' => '0.00', 'memo' => 'Penerimaan pelanggan'],
            ['account_id' => $revenue->id, 'debit' => '0.00', 'credit' => '1000.00', 'memo' => 'Pendapatan'],
        ]);
        $this->createPostedEntry($bmm, $period, 'JV-GL-0002', $period->starts_on->copy()->addDays(5)->toDateString(), 'Biaya bank', [
            ['account_id' => $cash->id, 'debit' => '0.00', 'credit' => '250.00', 'memo' => 'Biaya transfer'],
            ['account_id' => $expense->id, 'debit' => '250.00', 'credit' => '0.00', 'memo' => 'Biaya transfer'],
        ]);
        $this->createDraftEntry($bmm, $period, 'JV-GL-DRAFT', $period->starts_on->copy()->addDays(6)->toDateString(), 'Jurnal belum diposting', [
            ['account_id' => $cash->id, 'debit' => '99.00', 'credit' => '0.00'],
            ['account_id' => $revenue->id, 'debit' => '0.00', 'credit' => '99.00'],
        ]);
        $this->createPostedEntry($bmm, $otherPeriod, 'JV-GL-OTHER-PERIOD', $otherPeriod->starts_on->copy()->addDays(4)->toDateString(), 'Jurnal periode lain', [
            ['account_id' => $cash->id, 'debit' => '500.00', 'credit' => '0.00'],
            ['account_id' => $revenue->id, 'debit' => '0.00', 'credit' => '500.00'],
        ]);

        $this->withoutVite()
            ->actingAs($viewer)
            ->get(route('accounting.reports.general-ledger', [
                'legal_entity_id' => $bmm->id,
                'period_id' => $period->id,
                'chart_of_account_id' => $cash->id,
            ]))
            ->assertOk()
            ->assertSee('Buku Besar')
            ->assertSee('JV-GL-0001')
            ->assertSee('JV-GL-0002')
            ->assertDontSee('JV-GL-DRAFT')
            ->assertDontSee('JV-GL-OTHER-PERIOD')
            ->assertSee('750,00');
    }

    public function test_running_balance_uses_credit_normal_account_direction(): void
    {
        $this->seed();
        $bmm = LegalEntity::query()->where('code', 'BMM')->firstOrFail();
        $period = AccountingPeriod::query()
            ->where('legal_entity_id', $bmm->id)
            ->orderBy('starts_on')
            ->firstOrFail();
        $cash = $this->account($bmm, '1101');
        $revenue = $this->account($bmm, '4200');
        $viewer = $this->createScopedReportUser($bmm);

        $this->createPostedEntry($bmm, $period, 'JV-GL-CREDIT', $period->starts_on->copy()->addDays(4)->toDateString(), 'Pendapatan penjualan', [
            ['account_id' => $cash->id, 'debit' => '1250.00', 'credit' => '0.00'],
            ['account_id' => $revenue->id, 'debit' => '0.00', 'credit' => '1250.00'],
        ]);

        $this->withoutVite()
            ->actingAs($viewer)
            ->get(route('accounting.reports.general-ledger', [
                'legal_entity_id' => $bmm->id,
                'period_id' => $period->id,
                'chart_of_account_id' => $revenue->id,
            ]))
            ->assertOk()
            ->assertSee('Saldo normal kredit')
            ->assertSee('1.250,00');
    }

    public function test_user_cannot_open_another_entity_or_a_non_postable_account(): void
    {
        $this->seed();
        $bmm = LegalEntity::query()->where('code', 'BMM')->firstOrFail();
        $tmp = LegalEntity::query()->where('code', 'TMP')->firstOrFail();
        $viewer = $this->createScopedReportUser($bmm);
        $bmmPeriod = AccountingPeriod::query()->where('legal_entity_id', $bmm->id)->firstOrFail();
        $header = ChartOfAccount::query()
            ->where('legal_entity_id', $bmm->id)
            ->where('is_postable', false)
            ->firstOrFail();

        $this->actingAs($viewer)
            ->get(route('accounting.reports.general-ledger', ['legal_entity_id' => $tmp->id]))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('accounting.reports.general-ledger', [
                'legal_entity_id' => $bmm->id,
                'period_id' => $bmmPeriod->id,
                'chart_of_account_id' => $header->id,
            ]))
            ->assertNotFound();
    }

    #[DataProvider('historicalAccountStates')]
    public function test_posted_history_remains_visible_after_account_is_unavailable_for_new_posting(array $state): void
    {
        $this->seed();
        $entity = LegalEntity::query()->where('code', 'BMM')->firstOrFail();
        $period = AccountingPeriod::query()->where('legal_entity_id', $entity->id)->firstOrFail();
        $cash = $this->account($entity, '1101');
        $revenue = $this->account($entity, '4200');
        $viewer = $this->createScopedReportUser($entity);
        $this->createPostedEntry($entity, $period, 'JV-GL-HISTORY', $period->starts_on->toDateString(), 'Histori akun', [
            ['account_id' => $cash->id, 'debit' => '100.01', 'credit' => '0.00'],
            ['account_id' => $revenue->id, 'debit' => '0.00', 'credit' => '100.01'],
        ]);
        $cash->update($state);

        $this->withoutVite()->actingAs($viewer)
            ->get(route('accounting.reports.general-ledger', [
                'legal_entity_id' => $entity->id,
                'period_id' => $period->id,
                'chart_of_account_id' => $cash->id,
            ]))
            ->assertOk()
            ->assertSee('JV-GL-HISTORY')
            ->assertViewHas('totals', ['debit' => '100.01', 'credit' => '0.00', 'closing_balance' => '100.01']);
    }

    /**
     * @return array<string, array{array<string, bool>}>
     */
    public static function historicalAccountStates(): array
    {
        return [
            'inactive' => [['is_active' => false]],
            'nonpostable' => [['is_postable' => false]],
        ];
    }

    private function account(LegalEntity $legalEntity, string $code): ChartOfAccount
    {
        return ChartOfAccount::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('code', $code)
            ->firstOrFail();
    }

    /**
     * @param  list<array{account_id: int, debit: string, credit: string, memo?: string}>  $lines
     */
    private function createPostedEntry(
        LegalEntity $legalEntity,
        AccountingPeriod $period,
        string $entryNo,
        string $entryDate,
        string $description,
        array $lines,
    ): void {
        $entry = $this->createDraftEntry($legalEntity, $period, $entryNo, $entryDate, $description, $lines);

        $entry->forceFill([
            'status' => JournalEntry::STATUS_POSTED,
            'posted_at' => now(),
        ])->save();
    }

    /**
     * @param  list<array{account_id: int, debit: string, credit: string, memo?: string}>  $lines
     */
    private function createDraftEntry(
        LegalEntity $legalEntity,
        AccountingPeriod $period,
        string $entryNo,
        string $entryDate,
        string $description,
        array $lines,
    ): JournalEntry {
        $totalDebit = collect($lines)->sum(fn (array $line): float => (float) $line['debit']);
        $totalCredit = collect($lines)->sum(fn (array $line): float => (float) $line['credit']);
        $entry = JournalEntry::query()->create([
            'legal_entity_id' => $legalEntity->id,
            'accounting_period_id' => $period->id,
            'entry_no' => $entryNo,
            'entry_date' => $entryDate,
            'source_type' => 'manual',
            'status' => JournalEntry::STATUS_DRAFT,
            'description' => $description,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
        ]);

        foreach ($lines as $index => $line) {
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'chart_of_account_id' => $line['account_id'],
                'line_no' => $index + 1,
                'memo' => $line['memo'] ?? null,
                'debit' => $line['debit'],
                'credit' => $line['credit'],
            ]);
        }

        return $entry;
    }

    private function createScopedReportUser(LegalEntity $legalEntity): User
    {
        $user = User::factory()->create();

        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('code', 'viewer')->firstOrFail()->id,
            'legal_entity_id' => $legalEntity->id,
            'scope_key' => 'legal_entity',
            'assigned_at' => now(),
        ]);

        return $user;
    }
}
