<?php

namespace App\Http\Controllers\Accounting;

use App\Accounting\JournalEntryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\PostJournalEntryRequest;
use App\Http\Requests\Accounting\StoreJournalEntryRequest;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\Branch;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\LegalEntity;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class JournalEntryController extends Controller
{
    public function index(Request $request, JournalEntryService $journalEntryService): View
    {
        /** @var User $user */
        $user = $request->user();
        $legalEntities = $journalEntryService->availableLegalEntities($user);
        $selectedLegalEntity = $this->selectedLegalEntity($request, $legalEntities);
        $status = $request->string('status')->toString();

        $journalEntries = JournalEntry::query()
            ->whereBelongsTo($selectedLegalEntity)
            ->when(
                in_array($status, [JournalEntry::STATUS_DRAFT, JournalEntry::STATUS_POSTED], true),
                static fn ($query) => $query->where('status', $status),
            )
            ->with([
                'accountingPeriod:id,code',
                'branch:id,code,name',
                'postedBy:id,name',
            ])
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('accounting.journals.index', [
            'journalEntries' => $journalEntries,
            'legalEntities' => $legalEntities,
            'selectedLegalEntity' => $selectedLegalEntity,
            'selectedStatus' => $status,
        ]);
    }

    public function create(Request $request, JournalEntryService $journalEntryService): View
    {
        /** @var User $user */
        $user = $request->user();
        $legalEntities = $journalEntryService->availableLegalEntities($user);
        $selectedLegalEntity = $this->selectedLegalEntity($request, $legalEntities);
        $legalEntityIds = $legalEntities->modelKeys();

        $accountsByEntity = ChartOfAccount::query()
            ->whereIn('legal_entity_id', $legalEntityIds)
            ->where('is_active', true)
            ->where('is_postable', true)
            ->orderBy('code')
            ->get(['id', 'legal_entity_id', 'code', 'name'])
            ->groupBy('legal_entity_id')
            ->map(static fn (Collection $accounts): array => $accounts
                ->map(static fn (ChartOfAccount $account): array => [
                    'id' => $account->id,
                    'label' => "{$account->code} — {$account->name}",
                ])
                ->values()
                ->all())
            ->all();

        $branchesByEntity = Branch::query()
            ->whereIn('legal_entity_id', $legalEntityIds)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'legal_entity_id', 'code', 'name'])
            ->groupBy('legal_entity_id')
            ->map(static fn (Collection $branches): array => $branches
                ->map(static fn (Branch $branch): array => [
                    'id' => $branch->id,
                    'label' => "{$branch->code} — {$branch->name}",
                ])
                ->values()
                ->all())
            ->all();

        $openPeriodsByEntity = AccountingPeriod::query()
            ->whereIn('legal_entity_id', $legalEntityIds)
            ->where('status', 'open')
            ->orderBy('starts_on')
            ->get(['id', 'legal_entity_id', 'code', 'starts_on', 'ends_on'])
            ->groupBy('legal_entity_id')
            ->map(static fn (Collection $periods): array => $periods
                ->map(static fn (AccountingPeriod $period): array => [
                    'code' => $period->code,
                    'starts_on' => $period->starts_on->toDateString(),
                    'ends_on' => $period->ends_on->toDateString(),
                ])
                ->values()
                ->all())
            ->all();

        return view('accounting.journals.create', [
            'journalFormOptions' => [
                'accountsByEntity' => $accountsByEntity,
                'branchesByEntity' => $branchesByEntity,
                'openPeriodsByEntity' => $openPeriodsByEntity,
            ],
            'legalEntities' => $legalEntities,
            'selectedLegalEntity' => $selectedLegalEntity,
        ]);
    }

    public function store(
        StoreJournalEntryRequest $request,
        JournalEntryService $journalEntryService,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $journalEntry = $journalEntryService->createDraft(
            $request->validated(),
            $user,
            $request->ip(),
            $request->userAgent(),
        );

        return to_route('accounting.journals.show', $journalEntry)
            ->with('status', "Jurnal {$journalEntry->entry_no} disimpan sebagai draft.");
    }

    public function show(
        Request $request,
        JournalEntry $journalEntry,
        JournalEntryService $journalEntryService,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $journalEntryService->authorizeJournal($user, $journalEntry);

        $journalEntry->load([
            'accountingPeriod',
            'branch',
            'legalEntity',
            'lines.branch',
            'lines.chartOfAccount',
            'postedBy',
        ]);

        return view('accounting.journals.show', [
            'canPost' => $journalEntry->isDraft()
                && $journalEntry->source_type === 'manual'
                && $journalEntry->accountingPeriod->isOpen(),
            'journalEntry' => $journalEntry,
        ]);
    }

    public function post(
        PostJournalEntryRequest $request,
        JournalEntry $journalEntry,
        JournalEntryService $journalEntryService,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $journalEntry = $journalEntryService->post(
            $journalEntry,
            $user,
            $request->ip(),
            $request->userAgent(),
        );

        return to_route('accounting.journals.show', $journalEntry)
            ->with('status', "Jurnal {$journalEntry->entry_no} berhasil diposting.");
    }

    /**
     * @param  Collection<int, LegalEntity>  $legalEntities
     */
    private function selectedLegalEntity(Request $request, Collection $legalEntities): LegalEntity
    {
        $selectedLegalEntityId = $request->integer('legal_entity_id') ?: $legalEntities->first()?->id;
        $legalEntity = $legalEntities->firstWhere('id', $selectedLegalEntityId);

        abort_if($legalEntity === null, 403, 'Anda tidak memiliki hak akses jurnal pada perusahaan ini.');

        return $legalEntity;
    }
}
