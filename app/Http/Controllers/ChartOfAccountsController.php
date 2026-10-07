<?php

namespace App\Http\Controllers;

use App\Http\Requests\Accounting\StoreChartOfAccountRequest;
use App\Http\Requests\Accounting\UpdateChartOfAccountRequest;
use App\Models\Access\AccessAudit;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\LegalEntity;
use App\PermissionCode;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ChartOfAccountsController extends Controller
{
    public function index(Request $request): View
    {
        $selectedLegalEntityId = $request->integer('entity') ?: null;
        $category = $request->string('category')->toString();
        $status = $request->string('status')->toString();
        $search = trim($request->string('search')->toString());

        $accounts = ChartOfAccount::query()
            ->with(['legalEntity:id,code,name', 'parent:id,code,name'])
            ->when(
                $selectedLegalEntityId !== null,
                fn ($query) => $query->where('legal_entity_id', $selectedLegalEntityId),
            )
            ->when(
                array_key_exists($category, ChartOfAccount::CATEGORY_LABELS),
                fn ($query) => $query->where('category', $category),
            )
            ->when(
                $status === 'active',
                fn ($query) => $query->where('is_active', true),
            )
            ->when(
                $status === 'inactive',
                fn ($query) => $query->where('is_active', false),
            )
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->orderBy('legal_entity_id')
            ->orderBy('code')
            ->paginate(40)
            ->withQueryString();

        return view('accounting.accounts.index', [
            'accounts' => $accounts,
            'canManagePeriods' => $request->user()?->hasGlobalPermission(PermissionCode::PeriodManage->value) ?? false,
            'categories' => ChartOfAccount::CATEGORY_LABELS,
            'legalEntities' => $this->activeLegalEntities(),
            'selectedLegalEntityId' => $selectedLegalEntityId,
        ]);
    }

    public function create(Request $request): View
    {
        $selectedLegalEntityId = $request->integer('entity') ?: null;

        return view('accounting.accounts.create', [
            'account' => new ChartOfAccount,
            'categories' => ChartOfAccount::CATEGORY_LABELS,
            'legalEntities' => $this->activeLegalEntities(),
            'normalBalances' => ChartOfAccount::NORMAL_BALANCE_LABELS,
            'parentAccounts' => $this->parentAccounts(),
            'selectedLegalEntityId' => $selectedLegalEntityId,
        ]);
    }

    public function store(StoreChartOfAccountRequest $request): RedirectResponse
    {
        $account = DB::transaction(function () use ($request): ChartOfAccount {
            $account = ChartOfAccount::query()->create($request->validated());

            $this->audit($request, 'accounting.chart_of_account_created', $account, null);

            return $account;
        });

        return redirect()
            ->route('accounting.accounts.index', ['entity' => $account->legal_entity_id])
            ->with('status', "Akun {$account->code} berhasil ditambahkan.");
    }

    public function edit(ChartOfAccount $chartOfAccount): View
    {
        return view('accounting.accounts.edit', [
            'account' => $chartOfAccount,
            'categories' => ChartOfAccount::CATEGORY_LABELS,
            'legalEntities' => $this->activeLegalEntities(),
            'normalBalances' => ChartOfAccount::NORMAL_BALANCE_LABELS,
            'parentAccounts' => $this->parentAccounts($chartOfAccount),
            'selectedLegalEntityId' => $chartOfAccount->legal_entity_id,
        ]);
    }

    public function update(
        UpdateChartOfAccountRequest $request,
        ChartOfAccount $chartOfAccount,
    ): RedirectResponse {
        DB::transaction(function () use ($chartOfAccount, $request): void {
            $oldValues = $this->accountValues($chartOfAccount);

            $chartOfAccount->update($request->validated());

            $this->audit($request, 'accounting.chart_of_account_updated', $chartOfAccount, $oldValues);
        });

        return redirect()
            ->route('accounting.accounts.index', ['entity' => $chartOfAccount->legal_entity_id])
            ->with('status', "Akun {$chartOfAccount->code} berhasil diperbarui.");
    }

    /**
     * @return Collection<int, LegalEntity>
     */
    private function activeLegalEntities(): Collection
    {
        return LegalEntity::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    /**
     * @return Collection<int, ChartOfAccount>
     */
    private function parentAccounts(?ChartOfAccount $account = null): Collection
    {
        $query = ChartOfAccount::query()
            ->with('legalEntity:id,code')
            ->where('is_active', true)
            ->where('is_postable', false)
            ->orderBy('legal_entity_id')
            ->orderBy('code');

        if ($account !== null) {
            $query
                ->where('legal_entity_id', $account->legal_entity_id)
                ->whereNotIn('id', $this->descendantIds($account));
        }

        return $query->get(['id', 'legal_entity_id', 'code', 'name', 'category']);
    }

    /**
     * @return list<int>
     */
    private function descendantIds(ChartOfAccount $account): array
    {
        $descendantIds = [$account->id];
        $parentIds = [$account->id];

        while ($parentIds !== []) {
            $parentIds = ChartOfAccount::query()
                ->whereIn('parent_id', $parentIds)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $descendantIds = [...$descendantIds, ...$parentIds];
        }

        return $descendantIds;
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     */
    private function audit(
        Request $request,
        string $action,
        ChartOfAccount $account,
        ?array $oldValues,
    ): void {
        AccessAudit::query()->create([
            'actor_id' => $request->user()?->id,
            'action' => $action,
            'subject_type' => ChartOfAccount::class,
            'subject_id' => $account->id,
            'old_values' => $oldValues,
            'new_values' => $this->accountValues($account),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private function accountValues(ChartOfAccount $account): array
    {
        return [
            'legal_entity_id' => $account->legal_entity_id,
            'code' => $account->code,
            'name' => $account->name,
            'category' => $account->category,
            'normal_balance' => $account->normal_balance,
            'parent_id' => $account->parent_id,
            'is_postable' => $account->is_postable,
            'is_active' => $account->is_active,
        ];
    }
}
