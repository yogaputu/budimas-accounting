<?php

namespace App\Http\Controllers;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\DataSource;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\LegalEntity;
use App\PermissionCode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class AccountingDashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        $legalEntities = LegalEntity::query()
            ->where('is_active', true)
            ->withCount('branches')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $journalEntityIds = $legalEntities
            ->filter(fn (LegalEntity $entity): bool => $user->hasPermission(
                PermissionCode::JournalManage->value,
                $entity->id,
            ))
            ->modelKeys();
        $reportEntityIds = $legalEntities
            ->filter(fn (LegalEntity $entity): bool => $user->hasPermission(
                PermissionCode::ReportView->value,
                $entity->id,
            ))
            ->modelKeys();
        $canManageAccounts = $user->hasGlobalPermission(PermissionCode::JournalManage->value);
        $canManagePeriods = $user->hasGlobalPermission(PermissionCode::PeriodManage->value);
        $visibleEntityIds = collect([...$journalEntityIds, ...$reportEntityIds])
            ->unique()
            ->values()
            ->all();

        $dataSources = DataSource::query()
            ->where('is_active', true)
            ->orderBy('key')
            ->get(['key', 'name', 'kind', 'access_mode']);

        $activeRoleLabels = $user->roleAssignments()
            ->with('role')
            ->get()
            ->filter(static fn ($assignment): bool => $assignment->isActive())
            ->pluck('role.name')
            ->unique()
            ->values();

        $journalCounts = JournalEntry::query()
            ->when(
                $journalEntityIds !== [],
                fn ($query) => $query->whereIn('legal_entity_id', $journalEntityIds),
                fn ($query) => $query->whereRaw('1 = 0'),
            )
            ->selectRaw("SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft_count")
            ->selectRaw("SUM(CASE WHEN status = 'posted' THEN 1 ELSE 0 END) AS posted_count")
            ->first();

        $activeAccountCount = ChartOfAccount::query()
            ->where('is_active', true)
            ->when(
                ! $canManageAccounts,
                fn ($query) => $query->whereIn('legal_entity_id', $journalEntityIds),
            )
            ->count();
        $openPeriodCount = AccountingPeriod::query()
            ->where('status', 'open')
            ->when(
                ! $canManagePeriods,
                fn ($query) => $query->whereIn('legal_entity_id', $visibleEntityIds),
            )
            ->count();

        return view('accounting.dashboard', [
            'activeRoleLabels' => $activeRoleLabels,
            'canManageAccess' => $user->hasGlobalPermission(PermissionCode::AccessManage->value),
            'canManageAccounts' => $canManageAccounts,
            'canManagePeriods' => $canManagePeriods,
            'canManageJournals' => $journalEntityIds !== [],
            'canViewReports' => $reportEntityIds !== [],
            'canViewStaging' => $user->hasGlobalPermission(PermissionCode::SystemHealthView->value)
                || $legalEntities->contains(
                    fn (LegalEntity $entity): bool => $user->hasPermission(
                        PermissionCode::SystemHealthView->value,
                        $entity->id,
                    ),
                ),
            'activeAccountCount' => $activeAccountCount,
            'databaseConnection' => (string) config('database.default'),
            'dataSources' => $dataSources,
            'draftJournalCount' => (int) ($journalCounts?->draft_count ?? 0),
            'legalEntities' => $legalEntities->whereIn('id', $visibleEntityIds)->values(),
            'openPeriodCount' => $openPeriodCount,
            'postedJournalCount' => (int) ($journalCounts?->posted_count ?? 0),
            'user' => $user,
        ]);
    }
}
