<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\Accounting\LegalEntity;
use App\PermissionCode;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class GeneralLedgerController extends Controller
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

        $requestedEntityId = $request->filled('legal_entity_id') ? $request->integer('legal_entity_id') : null;
        $selectedEntityId = $requestedEntityId ?? $entities->first()?->id;
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

        $accounts = ChartOfAccount::query()
            ->where('legal_entity_id', $selectedEntity->id)
            ->where(static function (Builder $query) use ($selectedEntity): void {
                $query->where(static function (Builder $available): void {
                    $available->where('is_active', true)->where('is_postable', true);
                })->orWhereHas('journalLines.journalEntry', static function (Builder $entries) use ($selectedEntity): void {
                    $entries->where('legal_entity_id', $selectedEntity->id)
                        ->where('status', JournalEntry::STATUS_POSTED);
                });
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'normal_balance']);

        $requestedAccountId = $request->filled('chart_of_account_id')
            ? $request->integer('chart_of_account_id')
            : null;
        $selectedAccountId = $requestedAccountId ?? $accounts->first()?->id;
        $selectedAccount = $accounts->firstWhere('id', $selectedAccountId);

        abort_if(
            $requestedAccountId !== null && $selectedAccount === null,
            404,
            'Akun posting tidak ditemukan untuk perusahaan ini.',
        );

        /** @var Collection<int, JournalLine> $lines */
        $lines = collect();

        if ($selectedPeriod !== null && $selectedAccount !== null) {
            $lines = JournalLine::query()
                ->select([
                    'journal_lines.id',
                    'journal_lines.journal_entry_id',
                    'journal_lines.line_no',
                    'journal_lines.memo',
                    'journal_lines.debit',
                    'journal_lines.credit',
                    'journal_entries.entry_no as journal_entry_no',
                    'journal_entries.entry_date as journal_entry_date',
                    'journal_entries.description as journal_entry_description',
                ])
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_entries.legal_entity_id', $selectedEntity->id)
                ->where('journal_entries.accounting_period_id', $selectedPeriod->id)
                ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
                ->where('journal_lines.chart_of_account_id', $selectedAccount->id)
                ->orderBy('journal_entries.entry_date')
                ->orderBy('journal_entries.entry_no')
                ->orderBy('journal_lines.line_no')
                ->get();
        }

        $openingBalance = '0.00';
        $runningBalance = $openingBalance;

        $lines->each(function (JournalLine $line) use (&$runningBalance, $selectedAccount): void {
            $debit = bcadd((string) $line->debit, '0', 2);
            $credit = bcadd((string) $line->credit, '0', 2);
            $movement = $selectedAccount?->normal_balance === 'credit'
                ? bcsub($credit, $debit, 2)
                : bcsub($debit, $credit, 2);

            $runningBalance = bcadd($runningBalance, $movement, 2);
            $line->setAttribute('running_balance', $runningBalance);
        });

        return view('accounting.reports.general-ledger', [
            'accounts' => $accounts,
            'canManageAccounts' => $request->user()?->hasGlobalPermission(PermissionCode::JournalManage->value) ?? false,
            'canManageJournals' => $request->user()?->hasPermission(
                PermissionCode::JournalManage->value,
                $selectedEntity->id,
            ) ?? false,
            'entities' => $entities,
            'lines' => $lines,
            'openingBalance' => $openingBalance,
            'periods' => $periods,
            'selectedAccount' => $selectedAccount,
            'selectedEntity' => $selectedEntity,
            'selectedPeriod' => $selectedPeriod,
            'totals' => [
                'debit' => $this->total($lines, 'debit'),
                'credit' => $this->total($lines, 'credit'),
                'closing_balance' => $runningBalance,
            ],
        ]);
    }

    /**
     * @param  Collection<int, JournalLine>  $lines
     */
    private function total(Collection $lines, string $column): string
    {
        return $lines->reduce(
            static fn (string $total, JournalLine $line): string => bcadd($total, (string) $line->{$column}, 2),
            '0.00',
        );
    }
}
