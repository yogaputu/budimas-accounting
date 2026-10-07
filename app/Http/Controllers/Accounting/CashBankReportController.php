<?php

namespace App\Http\Controllers\Accounting;

use App\Accounting\AccountingAmount;
use App\Http\Controllers\Controller;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\LegalEntity;
use App\Models\User;
use App\PermissionCode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CashBankReportController extends Controller
{
    /**
     * @var list<string>
     */
    private const CASH_BANK_ACCOUNT_CODES = ['1101', '1102', '1103', '1104'];

    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $entities = $this->availableEntities($user);
        $selectedEntity = $this->selectedEntity($request, $entities);

        $periods = AccountingPeriod::query()
            ->where('legal_entity_id', $selectedEntity->id)
            ->orderByDesc('starts_on')
            ->get(['id', 'code', 'starts_on', 'ends_on', 'status']);

        $selectedPeriod = $this->selectedPeriod($request, $periods);
        $accounts = collect();
        $recentMovements = collect();

        if ($selectedPeriod !== null) {
            $accounts = $this->cashBankAccounts($selectedEntity, $selectedPeriod);
            $recentMovements = $this->recentMovements($selectedEntity, $selectedPeriod);
        }

        return view('accounting.reports.cash-bank', [
            'accounts' => $accounts,
            'canManageAccounts' => $user->hasGlobalPermission(PermissionCode::JournalManage->value),
            'canManageJournals' => $user->hasPermission(
                PermissionCode::JournalManage->value,
                $selectedEntity->id,
            ),
            'canViewDashboard' => $user->hasGlobalPermission(PermissionCode::AccountingDashboardView->value),
            'entities' => $entities,
            'periods' => $periods,
            'recentMovements' => $recentMovements,
            'selectedEntity' => $selectedEntity,
            'selectedPeriod' => $selectedPeriod,
            'totals' => [
                'period_debit' => AccountingAmount::sum($accounts->pluck('period_debit')),
                'period_credit' => AccountingAmount::sum($accounts->pluck('period_credit')),
                'net_movement' => AccountingAmount::sum($accounts->pluck('net_movement')),
            ],
        ]);
    }

    /**
     * @return Collection<int, LegalEntity>
     */
    private function availableEntities(User $user): Collection
    {
        return LegalEntity::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->filter(fn (LegalEntity $entity): bool => $user->hasPermission(
                PermissionCode::ReportView->value,
                $entity->id,
            ))
            ->values();
    }

    /**
     * @param  Collection<int, LegalEntity>  $entities
     */
    private function selectedEntity(Request $request, Collection $entities): LegalEntity
    {
        $selectedEntityId = $request->integer('legal_entity_id') ?: $entities->first()?->id;
        $selectedEntity = $entities->firstWhere('id', $selectedEntityId);

        abort_if($selectedEntity === null, 403, 'Anda tidak memiliki hak akses untuk laporan perusahaan ini.');

        return $selectedEntity;
    }

    /**
     * @param  Collection<int, AccountingPeriod>  $periods
     */
    private function selectedPeriod(Request $request, Collection $periods): ?AccountingPeriod
    {
        $selectedPeriodId = $request->integer('period_id') ?: $periods->first()?->id;
        $selectedPeriod = $periods->firstWhere('id', $selectedPeriodId);

        abort_if(
            $request->filled('period_id') && $selectedPeriod === null,
            404,
            'Periode buku tidak ditemukan untuk perusahaan yang dipilih.',
        );

        return $selectedPeriod;
    }

    /**
     * @return Collection<int, ChartOfAccount>
     */
    private function cashBankAccounts(
        LegalEntity $legalEntity,
        AccountingPeriod $accountingPeriod,
    ): Collection {
        return ChartOfAccount::query()
            ->select([
                'chart_of_accounts.id',
                'chart_of_accounts.code',
                'chart_of_accounts.name',
                'chart_of_accounts.is_active',
            ])
            ->selectRaw('COALESCE(SUM(CASE WHEN journal_entries.id IS NOT NULL THEN journal_lines.debit ELSE 0 END), 0) AS period_debit')
            ->selectRaw('COALESCE(SUM(CASE WHEN journal_entries.id IS NOT NULL THEN journal_lines.credit ELSE 0 END), 0) AS period_credit')
            ->leftJoin('journal_lines', 'journal_lines.chart_of_account_id', '=', 'chart_of_accounts.id')
            ->leftJoin('journal_entries', function (JoinClause $join) use ($accountingPeriod, $legalEntity): void {
                $join->on('journal_entries.id', '=', 'journal_lines.journal_entry_id')
                    ->where('journal_entries.legal_entity_id', '=', $legalEntity->id)
                    ->where('journal_entries.accounting_period_id', '=', $accountingPeriod->id)
                    ->where('journal_entries.status', '=', JournalEntry::STATUS_POSTED);
            })
            ->where('chart_of_accounts.legal_entity_id', $legalEntity->id)
            ->whereIn('chart_of_accounts.code', self::CASH_BANK_ACCOUNT_CODES)
            ->groupBy([
                'chart_of_accounts.id',
                'chart_of_accounts.code',
                'chart_of_accounts.name',
                'chart_of_accounts.is_active',
            ])
            ->orderBy('chart_of_accounts.code')
            ->get()
            ->map(static function (ChartOfAccount $account): ChartOfAccount {
                $debit = bcadd((string) $account->period_debit, '0', 2);
                $credit = bcadd((string) $account->period_credit, '0', 2);

                $account->setAttribute('period_debit', $debit);
                $account->setAttribute('period_credit', $credit);
                $account->setAttribute('net_movement', bcsub($debit, $credit, 2));

                return $account;
            });
    }

    /**
     * @return Collection<int, object>
     */
    private function recentMovements(
        LegalEntity $legalEntity,
        AccountingPeriod $accountingPeriod,
    ): Collection {
        return DB::table('journal_lines')
            ->select([
                'journal_lines.id',
                'journal_lines.line_no',
                'journal_lines.memo',
                'journal_lines.debit',
                'journal_lines.credit',
                'journal_entries.entry_no',
                'journal_entries.entry_date',
                'journal_entries.description as journal_description',
                'chart_of_accounts.code as account_code',
                'chart_of_accounts.name as account_name',
                'branches.code as branch_code',
                'branches.name as branch_name',
            ])
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.chart_of_account_id')
            ->leftJoin('branches', 'branches.id', '=', 'journal_lines.branch_id')
            ->where('journal_entries.legal_entity_id', $legalEntity->id)
            ->where('journal_entries.accounting_period_id', $accountingPeriod->id)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('chart_of_accounts.legal_entity_id', $legalEntity->id)
            ->whereIn('chart_of_accounts.code', self::CASH_BANK_ACCOUNT_CODES)
            ->orderByDesc('journal_entries.entry_date')
            ->orderByDesc('journal_entries.id')
            ->orderBy('journal_lines.line_no')
            ->limit(30)
            ->get()
            ->map(static function (object $movement): object {
                $movement->debit = bcadd((string) $movement->debit, '0', 2);
                $movement->credit = bcadd((string) $movement->credit, '0', 2);
                $movement->entry_date = CarbonImmutable::parse($movement->entry_date);
                $movement->branch_label = $movement->branch_code === null
                    ? null
                    : "{$movement->branch_code} — {$movement->branch_name}";

                return $movement;
            });
    }
}
