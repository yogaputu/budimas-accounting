<?php

namespace Tests\Feature;

use App\Models\Access\Role;
use App\Models\Access\RoleAssignment;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\Accounting\LegalEntity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CashBankReportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The report is intentionally read-only: it only sums lines belonging to
     * posted entries in the selected entity and accounting period.
     */
    public function test_scoped_viewer_sees_only_posted_cash_and_bank_movements_in_the_selected_period(): void
    {
        $this->seed();

        $bmm = $this->entity('BMM');
        $period = $this->firstPeriod($bmm);
        $nextPeriod = AccountingPeriod::query()
            ->where('legal_entity_id', $bmm->id)
            ->where('starts_on', '>', $period->starts_on)
            ->orderBy('starts_on')
            ->firstOrFail();
        $viewer = $this->createScopedViewer($bmm);
        $cash = $this->account($bmm, '1101');
        $bank = $this->account($bmm, '1103');
        $income = $this->account($bmm, '4100');
        $expense = $this->account($bmm, '6205');

        $cashReceipt = $this->draftJournal($bmm, $period, 'JV-BMM-KB-0001', $period->starts_on->toDateString(), 'Penerimaan kas');
        $this->addLines($cashReceipt, $cash, $income, '1250000.00', 'Kas diterima');
        $this->markPosted($cashReceipt, $viewer);

        $bankFee = $this->draftJournal($bmm, $period, 'JV-BMM-KB-0002', $period->starts_on->toDateString(), 'Biaya administrasi bank');
        $this->addLines($bankFee, $expense, $bank, '125000.00', 'Biaya bank');
        $this->markPosted($bankFee, $viewer);

        $draft = $this->draftJournal($bmm, $period, 'JV-BMM-KB-DRAFT', $period->starts_on->toDateString(), 'Draft tidak masuk laporan');
        $this->addLines($draft, $cash, $income, '500000.00', 'Draft kas');

        $nextPeriodJournal = $this->draftJournal($bmm, $nextPeriod, 'JV-BMM-KB-NEXT', $nextPeriod->starts_on->toDateString(), 'Periode lain');
        $this->addLines($nextPeriodJournal, $cash, $income, '90000.00', 'Kas periode lain');
        $this->markPosted($nextPeriodJournal, $viewer);

        $this->withoutVite()
            ->actingAs($viewer)
            ->get(route('accounting.reports.cash-bank', [
                'legal_entity_id' => $bmm->id,
                'period_id' => $period->id,
            ]))
            ->assertOk()
            ->assertSee('Ringkasan akun Kas &amp; Bank', false)
            ->assertSee('Kas Besar')
            ->assertSee('Bank Operasional')
            ->assertSee('1.250.000,00')
            ->assertSee('125.000,00')
            ->assertSee('JV-BMM-KB-0001')
            ->assertSee('JV-BMM-KB-0002')
            ->assertDontSee('JV-BMM-KB-DRAFT')
            ->assertDontSee('JV-BMM-KB-NEXT')
            ->assertDontSee('1.840.000,00');
    }

    public function test_viewer_cannot_open_a_cash_and_bank_report_for_an_unassigned_entity(): void
    {
        $this->seed();

        $bmm = $this->entity('BMM');
        $tmp = $this->entity('TMP');
        $viewer = $this->createScopedViewer($bmm);

        $this->actingAs($viewer)
            ->get(route('accounting.reports.cash-bank', ['legal_entity_id' => $tmp->id]))
            ->assertForbidden();
    }

    public function test_user_without_report_permission_is_forbidden_from_the_cash_and_bank_report(): void
    {
        $this->seed();

        $this->actingAs(User::factory()->create())
            ->get(route('accounting.reports.cash-bank'))
            ->assertForbidden();
    }

    public function test_inactive_cash_account_keeps_exact_decimal_movements(): void
    {
        $this->seed();
        $entity = $this->entity('BMM');
        $period = $this->firstPeriod($entity);
        $viewer = $this->createScopedViewer($entity);
        $cash = $this->account($entity, '1101');
        $income = $this->account($entity, '4100');
        $receipt = $this->draftJournal($entity, $period, 'JV-CB-DECIMAL', $period->starts_on->toDateString(), 'Penerimaan desimal');
        $this->addLines($receipt, $cash, $income, '100.01', 'Penerimaan kas');
        $this->markPosted($receipt, $viewer);
        $refund = $this->draftJournal($entity, $period, 'JV-CB-REFUND', $period->starts_on->toDateString(), 'Pengembalian kas');
        $this->addLines($refund, $income, $cash, '100.00', 'Pengembalian kas');
        $this->markPosted($refund, $viewer);
        $cash->update(['is_active' => false]);

        $this->withoutVite()->actingAs($viewer)
            ->get(route('accounting.reports.cash-bank', [
                'legal_entity_id' => $entity->id,
                'period_id' => $period->id,
            ]))
            ->assertOk()
            ->assertSee('JV-CB-DECIMAL')
            ->assertSee('JV-CB-REFUND')
            ->assertViewHas('totals', [
                'period_debit' => '100.01',
                'period_credit' => '100.00',
                'net_movement' => '0.01',
            ]);
    }

    private function entity(string $code): LegalEntity
    {
        return LegalEntity::query()->where('code', $code)->firstOrFail();
    }

    private function firstPeriod(LegalEntity $legalEntity): AccountingPeriod
    {
        return AccountingPeriod::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->orderBy('starts_on')
            ->firstOrFail();
    }

    private function account(LegalEntity $legalEntity, string $code): ChartOfAccount
    {
        return ChartOfAccount::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('code', $code)
            ->firstOrFail();
    }

    private function createScopedViewer(LegalEntity $legalEntity): User
    {
        $viewer = User::factory()->create();

        RoleAssignment::query()->create([
            'user_id' => $viewer->id,
            'role_id' => Role::query()->where('code', 'viewer')->firstOrFail()->id,
            'legal_entity_id' => $legalEntity->id,
            'scope_key' => 'entity',
            'assigned_at' => now(),
        ]);

        return $viewer;
    }

    private function draftJournal(
        LegalEntity $legalEntity,
        AccountingPeriod $period,
        string $entryNo,
        string $entryDate,
        string $description,
    ): JournalEntry {
        return JournalEntry::query()->create([
            'legal_entity_id' => $legalEntity->id,
            'accounting_period_id' => $period->id,
            'entry_no' => $entryNo,
            'entry_date' => $entryDate,
            'source_type' => 'manual',
            'status' => JournalEntry::STATUS_DRAFT,
            'description' => $description,
            'total_debit' => '1250000.00',
            'total_credit' => '1250000.00',
        ]);
    }

    private function addLines(
        JournalEntry $journalEntry,
        ChartOfAccount $debitAccount,
        ChartOfAccount $creditAccount,
        string $amount,
        string $memo,
    ): void {
        JournalLine::query()->create([
            'journal_entry_id' => $journalEntry->id,
            'chart_of_account_id' => $debitAccount->id,
            'line_no' => 1,
            'memo' => $memo,
            'debit' => $amount,
            'credit' => '0.00',
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $journalEntry->id,
            'chart_of_account_id' => $creditAccount->id,
            'line_no' => 2,
            'memo' => $memo,
            'debit' => '0.00',
            'credit' => $amount,
        ]);
    }

    private function markPosted(JournalEntry $journalEntry, User $user): void
    {
        $journalEntry->forceFill([
            'status' => JournalEntry::STATUS_POSTED,
            'posted_by' => $user->id,
            'posted_at' => now(),
        ])->save();
    }
}
