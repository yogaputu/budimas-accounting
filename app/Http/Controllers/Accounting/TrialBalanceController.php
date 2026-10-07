<?php

namespace App\Http\Controllers\Accounting;

use App\Accounting\AccountingAmount;
use App\Http\Controllers\Controller;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\LegalEntity;
use App\PermissionCode;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;

final class TrialBalanceController extends Controller
{
    public function __invoke(Request $request): View
    {
        $entities = LegalEntity::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->filter(fn (LegalEntity $entity): bool => $request->user()?->hasPermission(
                PermissionCode::ReportView->value,
                $entity->id,
            ) ?? false)
            ->values();

        $selectedEntityId = $request->integer('legal_entity_id') ?: $entities->first()?->id;
        $selectedEntity = $entities->firstWhere('id', $selectedEntityId);

        abort_if($selectedEntity === null, 403, 'Anda tidak memiliki hak akses untuk laporan perusahaan ini.');

        $periods = AccountingPeriod::query()
            ->where('legal_entity_id', $selectedEntity->id)
            ->orderByDesc('starts_on')
            ->get(['id', 'code', 'starts_on', 'ends_on', 'status']);

        $requestedPeriodId = $request->filled('period_id') ? $request->integer('period_id') : null;
        $selectedPeriodId = $requestedPeriodId ?? $periods->first()?->id;
        $selectedPeriod = $periods->firstWhere('id', $selectedPeriodId);

        abort_if(
            $requestedPeriodId !== null && $selectedPeriod === null,
            404,
            'Periode buku tidak ditemukan untuk perusahaan ini.',
        );

        $accounts = collect();

        if ($selectedPeriod !== null) {
            $accounts = ChartOfAccount::query()
                ->select([
                    'chart_of_accounts.id',
                    'chart_of_accounts.code',
                    'chart_of_accounts.name',
                    'chart_of_accounts.category',
                    'chart_of_accounts.normal_balance',
                    'chart_of_accounts.is_postable',
                ])
                ->selectRaw('COALESCE(SUM(CASE WHEN journal_entries.id IS NOT NULL THEN journal_lines.debit ELSE 0 END), 0) AS period_debit')
                ->selectRaw('COALESCE(SUM(CASE WHEN journal_entries.id IS NOT NULL THEN journal_lines.credit ELSE 0 END), 0) AS period_credit')
                ->leftJoin('journal_lines', 'journal_lines.chart_of_account_id', '=', 'chart_of_accounts.id')
                ->leftJoin('journal_entries', function (JoinClause $join) use ($selectedEntity, $selectedPeriod): void {
                    $join->on('journal_entries.id', '=', 'journal_lines.journal_entry_id')
                        ->where('journal_entries.legal_entity_id', '=', $selectedEntity->id)
                        ->where('journal_entries.accounting_period_id', '=', $selectedPeriod->id)
                        ->where('journal_entries.status', '=', JournalEntry::STATUS_POSTED);
                })
                ->where('chart_of_accounts.legal_entity_id', $selectedEntity->id)
                ->where(static function (Builder $query): void {
                    $query->where('chart_of_accounts.is_active', true)
                        ->orWhereNotNull('journal_entries.id');
                })
                ->groupBy([
                    'chart_of_accounts.id',
                    'chart_of_accounts.code',
                    'chart_of_accounts.name',
                    'chart_of_accounts.category',
                    'chart_of_accounts.normal_balance',
                    'chart_of_accounts.is_postable',
                ])
                ->orderBy('chart_of_accounts.code')
                ->get()
                ->map(static function (ChartOfAccount $account): ChartOfAccount {
                    $debit = bcadd((string) $account->period_debit, '0', 2);
                    $credit = bcadd((string) $account->period_credit, '0', 2);
                    $balance = bcsub($debit, $credit, 2);

                    $account->setAttribute('period_debit', $debit);
                    $account->setAttribute('period_credit', $credit);
                    $account->setAttribute('balance_debit', bccomp($balance, '0', 2) > 0 ? $balance : '0.00');
                    $account->setAttribute('balance_credit', bccomp($balance, '0', 2) < 0 ? bcsub('0', $balance, 2) : '0.00');

                    return $account;
                });
        }

        return view('accounting.reports.trial-balance', [
            'accounts' => $accounts,
            'canManageAccounts' => $request->user()?->hasGlobalPermission(PermissionCode::JournalManage->value) ?? false,
            'canManageJournals' => $request->user()?->hasPermission(
                PermissionCode::JournalManage->value,
                $selectedEntity->id,
            ) ?? false,
            'entities' => $entities,
            'periods' => $periods,
            'selectedEntity' => $selectedEntity,
            'selectedPeriod' => $selectedPeriod,
            'totals' => [
                'period_debit' => AccountingAmount::sum($accounts->pluck('period_debit')),
                'period_credit' => AccountingAmount::sum($accounts->pluck('period_credit')),
                'balance_debit' => AccountingAmount::sum($accounts->pluck('balance_debit')),
                'balance_credit' => AccountingAmount::sum($accounts->pluck('balance_credit')),
            ],
        ]);
    }
}
