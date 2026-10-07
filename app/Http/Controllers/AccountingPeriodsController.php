<?php

namespace App\Http\Controllers;

use App\Http\Requests\Accounting\CloseAccountingPeriodRequest;
use App\Http\Requests\Accounting\ReopenAccountingPeriodRequest;
use App\Http\Requests\Accounting\StoreAccountingPeriodRequest;
use App\Models\Access\AccessAudit;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\LegalEntity;
use App\PermissionCode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountingPeriodsController extends Controller
{
    public function index(Request $request): View
    {
        $selectedLegalEntityId = $request->integer('entity') ?: null;

        $periods = AccountingPeriod::query()
            ->with([
                'legalEntity:id,code,name',
                'closedBy:id,name',
            ])
            ->when(
                $selectedLegalEntityId !== null,
                fn ($query) => $query->where('legal_entity_id', $selectedLegalEntityId),
            )
            ->orderByDesc('ends_on')
            ->orderByDesc('id')
            ->paginate(24)
            ->withQueryString();

        $legalEntities = LegalEntity::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $latestPeriodEnd = AccountingPeriod::query()
            ->when(
                $selectedLegalEntityId !== null,
                fn ($query) => $query->where('legal_entity_id', $selectedLegalEntityId),
            )
            ->max('ends_on');
        $nextPeriodStartsOn = $latestPeriodEnd === null
            ? now()->startOfMonth()
            : Carbon::parse($latestPeriodEnd)->addDay()->startOfMonth();

        return view('accounting.periods.index', [
            'canManageAccounts' => $request->user()?->hasGlobalPermission(PermissionCode::JournalManage->value) ?? false,
            'legalEntities' => $legalEntities,
            'periods' => $periods,
            'selectedLegalEntityId' => $selectedLegalEntityId,
            'suggestedPeriod' => [
                'code' => $nextPeriodStartsOn->format('Y-m'),
                'starts_on' => $nextPeriodStartsOn->toDateString(),
                'ends_on' => $nextPeriodStartsOn->endOfMonth()->toDateString(),
            ],
        ]);
    }

    public function store(StoreAccountingPeriodRequest $request): RedirectResponse
    {
        $period = DB::transaction(function () use ($request): AccountingPeriod {
            $period = AccountingPeriod::query()->create($request->validated());

            $this->audit($request, 'accounting.period_created', $period, null);

            return $period;
        });

        return redirect()
            ->route('accounting.periods.index', ['entity' => $period->legal_entity_id])
            ->with('status', "Periode {$period->code} berhasil dibuat dan berstatus terbuka.");
    }

    public function close(
        CloseAccountingPeriodRequest $request,
        AccountingPeriod $accountingPeriod,
    ): RedirectResponse {
        DB::transaction(function () use ($accountingPeriod, $request): void {
            $period = AccountingPeriod::query()
                ->lockForUpdate()
                ->findOrFail($accountingPeriod->id);

            if ($period->isClosed()) {
                throw ValidationException::withMessages([
                    'period' => 'Periode ini sudah ditutup.',
                ]);
            }

            $hasUnpostedEntries = DB::table('journal_entries')
                ->where('accounting_period_id', $period->id)
                ->whereNotIn('status', ['posted', 'void'])
                ->exists();

            if ($hasUnpostedEntries) {
                throw ValidationException::withMessages([
                    'period' => 'Periode tidak dapat ditutup selama masih ada jurnal draft atau belum diposting.',
                ]);
            }

            $oldValues = $this->periodValues($period);

            $period->forceFill([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $request->user()->id,
            ])->save();

            $this->audit($request, 'accounting.period_closed', $period, $oldValues);
        });

        return back()->with('status', "Periode {$accountingPeriod->code} berhasil ditutup.");
    }

    public function reopen(
        ReopenAccountingPeriodRequest $request,
        AccountingPeriod $accountingPeriod,
    ): RedirectResponse {
        DB::transaction(function () use ($accountingPeriod, $request): void {
            $period = AccountingPeriod::query()
                ->lockForUpdate()
                ->findOrFail($accountingPeriod->id);

            if ($period->isOpen()) {
                throw ValidationException::withMessages([
                    'period' => 'Periode ini masih terbuka.',
                ]);
            }

            $hasLaterClosedPeriod = AccountingPeriod::query()
                ->where('legal_entity_id', $period->legal_entity_id)
                ->where('status', 'closed')
                ->where('ends_on', '>', $period->ends_on)
                ->exists();

            if ($hasLaterClosedPeriod) {
                throw ValidationException::withMessages([
                    'period' => 'Buka kembali periode yang paling akhir ditutup terlebih dahulu.',
                ]);
            }

            $oldValues = $this->periodValues($period);

            $period->forceFill([
                'status' => 'open',
                'closed_at' => null,
                'closed_by' => null,
            ])->save();

            $this->audit($request, 'accounting.period_reopened', $period, $oldValues);
        });

        return back()->with('status', "Periode {$accountingPeriod->code} dibuka kembali.");
    }

    /**
     * @param  array<string, int|string|null>|null  $oldValues
     */
    private function audit(
        Request $request,
        string $action,
        AccountingPeriod $period,
        ?array $oldValues,
    ): void {
        AccessAudit::query()->create([
            'actor_id' => $request->user()?->id,
            'action' => $action,
            'subject_type' => AccountingPeriod::class,
            'subject_id' => $period->id,
            'old_values' => $oldValues,
            'new_values' => $this->periodValues($period),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * @return array<string, int|string|null>
     */
    private function periodValues(AccountingPeriod $period): array
    {
        return [
            'legal_entity_id' => $period->legal_entity_id,
            'code' => $period->code,
            'starts_on' => $period->starts_on->toDateString(),
            'ends_on' => $period->ends_on->toDateString(),
            'status' => $period->status,
            'closed_at' => $period->closed_at?->toIso8601String(),
            'closed_by' => $period->closed_by,
        ];
    }
}
