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

final class TrialBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_trial_balance_only_includes_posted_entries_and_respects_entity_scope(): void
    {
        $this->seed();

        $bmm = LegalEntity::query()->where('code', 'BMM')->firstOrFail();
        $tmp = LegalEntity::query()->where('code', 'TMP')->firstOrFail();
        $period = AccountingPeriod::query()
            ->where('legal_entity_id', $bmm->id)
            ->orderBy('starts_on')
            ->firstOrFail();
        $viewer = User::factory()->create();

        RoleAssignment::query()->create([
            'user_id' => $viewer->id,
            'role_id' => Role::query()->where('code', 'viewer')->value('id'),
            'legal_entity_id' => $bmm->id,
            'scope_key' => 'entity',
            'assigned_at' => now(),
        ]);

        $cash = $this->account($bmm, '1101');
        $sales = $this->account($bmm, '4100');
        $postedEntry = $this->journalEntry($bmm, $period, 'JV-BMM-REPORT-0001', $period->starts_on->toDateString());
        $this->addLines($postedEntry, $cash, $sales, '1250000.00');
        $postedEntry->forceFill([
            'status' => JournalEntry::STATUS_POSTED,
            'posted_by' => $viewer->id,
            'posted_at' => now(),
        ])->save();

        $draftEntry = $this->journalEntry($bmm, $period, 'JV-BMM-REPORT-0002', $period->starts_on->toDateString());
        $this->addLines($draftEntry, $cash, $sales, '500000.00');

        $this->actingAs($viewer)
            ->get(route('accounting.reports.trial-balance', [
                'legal_entity_id' => $bmm->id,
                'period_id' => $period->id,
            ]))
            ->assertOk()
            ->assertSee('Neraca Saldo')
            ->assertSee('1.250.000,00')
            ->assertDontSee('1.750.000,00');

        $this->actingAs($viewer)
            ->get(route('accounting.reports.trial-balance', ['legal_entity_id' => $tmp->id]))
            ->assertForbidden();
    }

    public function test_inactive_historical_accounts_remain_in_balanced_decimal_totals(): void
    {
        $this->seed();
        $entity = LegalEntity::query()->where('code', 'BMM')->firstOrFail();
        $period = AccountingPeriod::query()->where('legal_entity_id', $entity->id)->firstOrFail();
        $viewer = User::factory()->create();
        RoleAssignment::query()->create([
            'user_id' => $viewer->id,
            'role_id' => Role::query()->where('code', 'viewer')->value('id'),
            'legal_entity_id' => $entity->id,
            'scope_key' => 'entity',
            'assigned_at' => now(),
        ]);
        $cash = $this->account($entity, '1101');
        $sales = $this->account($entity, '4100');
        $entry = $this->journalEntry($entity, $period, 'JV-TB-HISTORY', $period->starts_on->toDateString());
        $this->addLines($entry, $cash, $sales, '100.01');
        $entry->forceFill(['status' => JournalEntry::STATUS_POSTED, 'posted_at' => now()])->save();
        $cash->update(['is_active' => false, 'is_postable' => false]);

        $this->withoutVite()->actingAs($viewer)
            ->get(route('accounting.reports.trial-balance', [
                'legal_entity_id' => $entity->id,
                'period_id' => $period->id,
            ]))
            ->assertOk()
            ->assertSee($cash->name)
            ->assertViewHas('totals', [
                'period_debit' => '100.01',
                'period_credit' => '100.01',
                'balance_debit' => '100.01',
                'balance_credit' => '100.01',
            ]);
    }

    public function test_period_from_another_entity_returns_not_found(): void
    {
        $this->seed();
        $entity = LegalEntity::query()->where('code', 'BMM')->firstOrFail();
        $otherEntity = LegalEntity::query()->where('code', 'TMP')->firstOrFail();
        $otherPeriod = AccountingPeriod::query()->where('legal_entity_id', $otherEntity->id)->firstOrFail();
        $viewer = User::factory()->create();
        RoleAssignment::query()->create([
            'user_id' => $viewer->id,
            'role_id' => Role::query()->where('code', 'viewer')->value('id'),
            'legal_entity_id' => $entity->id,
            'scope_key' => 'entity',
            'assigned_at' => now(),
        ]);

        $this->actingAs($viewer)
            ->get(route('accounting.reports.trial-balance', [
                'legal_entity_id' => $entity->id,
                'period_id' => $otherPeriod->id,
            ]))
            ->assertNotFound();
    }

    private function account(LegalEntity $legalEntity, string $code): ChartOfAccount
    {
        return ChartOfAccount::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('code', $code)
            ->firstOrFail();
    }

    private function journalEntry(
        LegalEntity $legalEntity,
        AccountingPeriod $period,
        string $entryNo,
        string $entryDate,
    ): JournalEntry {
        return JournalEntry::query()->create([
            'legal_entity_id' => $legalEntity->id,
            'accounting_period_id' => $period->id,
            'entry_no' => $entryNo,
            'entry_date' => $entryDate,
            'source_type' => 'manual',
            'status' => JournalEntry::STATUS_DRAFT,
            'total_debit' => '1250000.00',
            'total_credit' => '1250000.00',
        ]);
    }

    private function addLines(
        JournalEntry $journalEntry,
        ChartOfAccount $debitAccount,
        ChartOfAccount $creditAccount,
        string $amount,
    ): void {
        JournalLine::query()->create([
            'journal_entry_id' => $journalEntry->id,
            'chart_of_account_id' => $debitAccount->id,
            'line_no' => 1,
            'debit' => $amount,
            'credit' => '0.00',
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $journalEntry->id,
            'chart_of_account_id' => $creditAccount->id,
            'line_no' => 2,
            'debit' => '0.00',
            'credit' => $amount,
        ]);
    }
}
