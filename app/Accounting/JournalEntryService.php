<?php

namespace App\Accounting;

use App\Models\Access\AccessAudit;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\Branch;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\LegalEntity;
use App\Models\User;
use App\PermissionCode;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class JournalEntryService
{
    private const MAX_AMOUNT = '999999999999999999.99';

    /**
     * @param  array{
     *     legal_entity_id: int,
     *     branch_id?: int|null,
     *     entry_date: string,
     *     description?: string|null,
     *     lines: list<array{
     *         chart_of_account_id: int,
     *         branch_id?: int|null,
     *         memo?: string|null,
     *         debit?: string|int|float|null,
     *         credit?: string|int|float|null
     *     }>
     * }  $attributes
     */
    public function createDraft(
        array $attributes,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): JournalEntry {
        return DB::transaction(function () use ($actor, $attributes, $ipAddress, $userAgent): JournalEntry {
            $legalEntity = LegalEntity::query()
                ->whereKey((int) ($attributes['legal_entity_id'] ?? 0))
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($legalEntity === null) {
                throw ValidationException::withMessages([
                    'legal_entity_id' => 'Perusahaan tidak ditemukan atau tidak aktif.',
                ]);
            }

            $errors = [];
            $branchId = $this->nullableInteger($attributes['branch_id'] ?? null, 'branch_id', $errors);

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $this->authorizeActor($actor, $legalEntity->id, $branchId);

            $description = $this->nullableTrimmedString($attributes['description'] ?? null);

            if ($description !== null && mb_strlen($description) > 255) {
                throw ValidationException::withMessages([
                    'description' => 'Deskripsi jurnal maksimal 255 karakter.',
                ]);
            }

            $entryDate = $this->parseEntryDate($attributes['entry_date'] ?? null);
            $accountingPeriod = $this->openPeriodForDate($legalEntity->id, $entryDate);
            $this->ensureBranchBelongsToEntity($branchId, $legalEntity->id, 'branch_id');

            $lines = $this->normalizeAndValidateLines(
                $attributes['lines'] ?? null,
                $legalEntity->id,
            );
            $this->authorizeLineBranches($actor, $legalEntity->id, $branchId, $lines);
            $totals = $this->calculateTotals($lines);
            $entry = JournalEntry::query()->create([
                'legal_entity_id' => $legalEntity->id,
                'branch_id' => $branchId,
                'accounting_period_id' => $accountingPeriod->id,
                'entry_no' => $this->nextEntryNumber($legalEntity, $entryDate),
                'entry_date' => $entryDate->toDateString(),
                'source_type' => 'manual',
                'status' => JournalEntry::STATUS_DRAFT,
                'description' => $description,
                'total_debit' => $totals['debit'],
                'total_credit' => $totals['credit'],
            ]);

            foreach ($lines as $index => $line) {
                $entry->lines()->create([
                    ...$line,
                    'line_no' => $index + 1,
                ]);
            }

            $this->audit(
                actor: $actor,
                action: 'journal.draft_created',
                journalEntry: $entry,
                oldValues: null,
                newValues: [
                    'entry_no' => $entry->entry_no,
                    'legal_entity_id' => $entry->legal_entity_id,
                    'accounting_period_id' => $entry->accounting_period_id,
                    'entry_date' => $entry->entry_date->toDateString(),
                    'status' => $entry->status,
                    'line_count' => count($lines),
                    'total_debit' => $totals['debit'],
                    'total_credit' => $totals['credit'],
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $entry->load([
                'accountingPeriod',
                'branch',
                'legalEntity',
                'lines.chartOfAccount',
            ]);
        });
    }

    public function post(
        JournalEntry $journalEntry,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): JournalEntry {
        return DB::transaction(function () use ($actor, $ipAddress, $journalEntry, $userAgent): JournalEntry {
            $legalEntity = LegalEntity::query()
                ->whereKey($journalEntry->legal_entity_id)
                ->lockForUpdate()
                ->first();

            $lockedJournalEntry = JournalEntry::query()
                ->with('lines')
                ->lockForUpdate()
                ->findOrFail($journalEntry->id);

            $this->authorizeJournal($actor, $lockedJournalEntry);

            if ($legalEntity === null
                || ! $legalEntity->is_active
                || (int) $legalEntity->id !== (int) $lockedJournalEntry->legal_entity_id) {
                throw ValidationException::withMessages([
                    'legal_entity_id' => 'Perusahaan tidak ditemukan atau tidak aktif.',
                ]);
            }

            if (! $lockedJournalEntry->isDraft()) {
                throw ValidationException::withMessages([
                    'journal_entry' => 'Hanya jurnal draft yang dapat diposting.',
                ]);
            }

            if ($lockedJournalEntry->source_type !== 'manual') {
                throw ValidationException::withMessages([
                    'journal_entry' => 'Jurnal dari sumber terintegrasi tidak dapat diposting melalui jurnal manual.',
                ]);
            }

            $entryDate = CarbonImmutable::instance($lockedJournalEntry->entry_date);
            $accountingPeriod = $this->openPeriodForDate(
                $lockedJournalEntry->legal_entity_id,
                $entryDate,
            );

            if ((int) $accountingPeriod->id !== (int) $lockedJournalEntry->accounting_period_id) {
                throw ValidationException::withMessages([
                    'journal_entry' => 'Periode jurnal tidak lagi sesuai dengan periode terbuka untuk tanggal transaksi.',
                ]);
            }

            $this->ensureBranchBelongsToEntity(
                $lockedJournalEntry->branch_id,
                $lockedJournalEntry->legal_entity_id,
                'journal_entry',
            );

            $lines = $this->normalizeAndValidateLines(
                $lockedJournalEntry->lines
                    ->map(static fn ($line): array => [
                        'chart_of_account_id' => $line->chart_of_account_id,
                        'branch_id' => $line->branch_id,
                        'memo' => $line->memo,
                        'debit' => $line->debit,
                        'credit' => $line->credit,
                    ])
                    ->all(),
                $lockedJournalEntry->legal_entity_id,
            );
            $totals = $this->calculateTotals($lines);

            $oldValues = [
                'status' => $lockedJournalEntry->status,
                'total_debit' => (string) $lockedJournalEntry->total_debit,
                'total_credit' => (string) $lockedJournalEntry->total_credit,
            ];

            $lockedJournalEntry->forceFill([
                'total_debit' => $totals['debit'],
                'total_credit' => $totals['credit'],
                'status' => JournalEntry::STATUS_POSTED,
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ])->save();

            $this->audit(
                actor: $actor,
                action: 'journal.posted',
                journalEntry: $lockedJournalEntry,
                oldValues: $oldValues,
                newValues: [
                    'status' => JournalEntry::STATUS_POSTED,
                    'total_debit' => $totals['debit'],
                    'total_credit' => $totals['credit'],
                    'posted_by' => $actor->id,
                    'posted_at' => $lockedJournalEntry->posted_at?->toIso8601String(),
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $lockedJournalEntry->fresh([
                'accountingPeriod',
                'branch',
                'legalEntity',
                'lines.chartOfAccount',
                'postedBy',
            ]);
        });
    }

    /**
     * @return Collection<int, LegalEntity>
     */
    public function availableLegalEntities(User $actor): Collection
    {
        return LegalEntity::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->filter(
                fn (LegalEntity $legalEntity): bool => $actor->hasPermission(
                    PermissionCode::JournalManage->value,
                    $legalEntity->id,
                ),
            )
            ->values();
    }

    public function authorizeActor(User $actor, int $legalEntityId, ?int $branchId = null): void
    {
        if (! $actor->hasPermission(PermissionCode::JournalManage->value, $legalEntityId, $branchId)) {
            throw new AuthorizationException('Anda tidak memiliki hak akses jurnal pada scope perusahaan ini.');
        }
    }

    public function authorizeJournal(User $actor, JournalEntry $journalEntry): void
    {
        $this->authorizeActor($actor, $journalEntry->legal_entity_id, $journalEntry->branch_id);
        $this->authorizeLineBranches(
            $actor,
            $journalEntry->legal_entity_id,
            $journalEntry->branch_id,
            $journalEntry->lines->map(static fn ($line): array => ['branch_id' => $line->branch_id])->all(),
        );
    }

    /**
     * @param  list<array{branch_id: int|null}>  $lines
     */
    private function authorizeLineBranches(User $actor, int $legalEntityId, ?int $branchId, array $lines): void
    {
        $effectiveBranchIds = array_unique(array_map(
            static fn (array $line): ?int => $line['branch_id'] ?? $branchId,
            $lines,
        ));

        foreach ($effectiveBranchIds as $effectiveBranchId) {
            $this->authorizeActor($actor, $legalEntityId, $effectiveBranchId);
        }
    }

    private function openPeriodForDate(int $legalEntityId, CarbonImmutable $entryDate): AccountingPeriod
    {
        $accountingPeriod = AccountingPeriod::query()
            ->where('legal_entity_id', $legalEntityId)
            ->where('status', 'open')
            ->whereDate('starts_on', '<=', $entryDate->toDateString())
            ->whereDate('ends_on', '>=', $entryDate->toDateString())
            ->lockForUpdate()
            ->first();

        if ($accountingPeriod === null) {
            throw ValidationException::withMessages([
                'entry_date' => 'Tanggal jurnal harus berada pada periode Accounting yang masih terbuka.',
            ]);
        }

        return $accountingPeriod;
    }

    /**
     * @return list<array{
     *     chart_of_account_id: int,
     *     branch_id: int|null,
     *     memo: string|null,
     *     debit: string,
     *     credit: string
     * }>
     */
    private function normalizeAndValidateLines(mixed $rawLines, int $legalEntityId): array
    {
        if (! is_array($rawLines) || count($rawLines) < 2) {
            throw ValidationException::withMessages([
                'lines' => 'Jurnal harus memiliki minimal dua baris.',
            ]);
        }

        $errors = [];
        $lines = [];

        foreach ($rawLines as $index => $rawLine) {
            if (! is_array($rawLine)) {
                $errors["lines.{$index}"] = 'Format baris jurnal tidak valid.';

                continue;
            }

            $accountId = $this->nullableInteger($rawLine['chart_of_account_id'] ?? null, "lines.{$index}.chart_of_account_id", $errors);
            $branchId = $this->nullableInteger($rawLine['branch_id'] ?? null, "lines.{$index}.branch_id", $errors);
            $debit = $this->normalizeAmount($rawLine['debit'] ?? null, "lines.{$index}.debit", $errors);
            $credit = $this->normalizeAmount($rawLine['credit'] ?? null, "lines.{$index}.credit", $errors);

            if ($accountId === null) {
                $errors["lines.{$index}.chart_of_account_id"] = 'Akun wajib dipilih.';
            }

            if ($debit !== null && $credit !== null) {
                $debitIsPositive = bccomp($debit, '0.00', 2) === 1;
                $creditIsPositive = bccomp($credit, '0.00', 2) === 1;

                if ($debitIsPositive === $creditIsPositive) {
                    $errors["lines.{$index}.debit"] = 'Setiap baris harus memiliki debit atau kredit positif, bukan keduanya.';
                }
            }

            if ($accountId !== null && $debit !== null && $credit !== null) {
                $lines[] = [
                    'chart_of_account_id' => $accountId,
                    'branch_id' => $branchId,
                    'memo' => $this->nullableTrimmedString($rawLine['memo'] ?? null),
                    'debit' => $debit,
                    'credit' => $credit,
                ];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->validateAccountsAndBranches($lines, $legalEntityId);
        $totals = $this->calculateTotals($lines);

        if (bccomp($totals['debit'], self::MAX_AMOUNT, 2) > 0
            || bccomp($totals['credit'], self::MAX_AMOUNT, 2) > 0) {
            throw ValidationException::withMessages([
                'lines' => 'Total jurnal melebihi batas nominal yang dapat disimpan.',
            ]);
        }

        if (bccomp($totals['debit'], $totals['credit'], 2) !== 0) {
            throw ValidationException::withMessages([
                'lines' => 'Total debit dan kredit harus seimbang sampai dua angka desimal.',
            ]);
        }

        return $lines;
    }

    /**
     * @param  list<array{
     *     chart_of_account_id: int,
     *     branch_id: int|null,
     *     memo: string|null,
     *     debit: string,
     *     credit: string
     * }>  $lines
     */
    private function validateAccountsAndBranches(array $lines, int $legalEntityId): void
    {
        $accountIds = array_values(array_unique(array_column($lines, 'chart_of_account_id')));
        $accounts = ChartOfAccount::query()
            ->whereIn('id', $accountIds)
            ->get(['id', 'legal_entity_id', 'is_active', 'is_postable'])
            ->keyBy('id');

        $branchIds = array_values(array_unique(array_filter(
            array_column($lines, 'branch_id'),
            static fn (?int $branchId): bool => $branchId !== null,
        )));
        $branches = Branch::query()
            ->whereIn('id', $branchIds)
            ->get(['id', 'legal_entity_id', 'is_active'])
            ->keyBy('id');

        $errors = [];

        foreach ($lines as $index => $line) {
            $account = $accounts->get($line['chart_of_account_id']);

            if ($account === null
                || (int) $account->legal_entity_id !== $legalEntityId
                || ! $account->is_active
                || ! $account->is_postable) {
                $errors["lines.{$index}.chart_of_account_id"] = 'Akun harus aktif, dapat diposting, dan berasal dari perusahaan yang dipilih.';
            }

            if ($line['branch_id'] !== null) {
                $branch = $branches->get($line['branch_id']);

                if ($branch === null || (int) $branch->legal_entity_id !== $legalEntityId || ! $branch->is_active) {
                    $errors["lines.{$index}.branch_id"] = 'Cabang baris jurnal harus aktif dan berasal dari perusahaan yang dipilih.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  list<array{debit: string, credit: string}>  $lines
     * @return array{debit: string, credit: string}
     */
    private function calculateTotals(array $lines): array
    {
        $debit = '0.00';
        $credit = '0.00';

        foreach ($lines as $line) {
            $debit = bcadd($debit, $line['debit'], 2);
            $credit = bcadd($credit, $line['credit'], 2);
        }

        return compact('credit', 'debit');
    }

    private function ensureBranchBelongsToEntity(?int $branchId, int $legalEntityId, string $field): void
    {
        if ($branchId === null) {
            return;
        }

        $branchIsAvailable = Branch::query()
            ->whereKey($branchId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('is_active', true)
            ->exists();

        if (! $branchIsAvailable) {
            throw ValidationException::withMessages([
                $field => 'Cabang harus aktif dan berasal dari perusahaan yang dipilih.',
            ]);
        }
    }

    private function nextEntryNumber(LegalEntity $legalEntity, CarbonImmutable $entryDate): string
    {
        $entityCode = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($legalEntity->code)) ?: 'ENTITY';
        $prefix = sprintf('JV-%s-%s-', $entityCode, $entryDate->format('Ym'));
        $latestEntryNumber = JournalEntry::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('entry_no', 'like', $prefix.'%')
            ->lockForUpdate()
            ->latest('id')
            ->value('entry_no');

        $sequence = 1;

        if (is_string($latestEntryNumber) && preg_match('/(\d+)$/', $latestEntryNumber, $matches) === 1) {
            $sequence = ((int) $matches[1]) + 1;
        }

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    private function parseEntryDate(mixed $value): CarbonImmutable
    {
        $date = is_string($value)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $value)
            : false;

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([
                'entry_date' => 'Tanggal jurnal tidak valid.',
            ]);
        }

        return $date;
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function nullableInteger(mixed $value, string $field, array &$errors = []): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            $errors[$field] = 'Nilai pilihan tidak valid.';

            return null;
        }

        return (int) $value;
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function normalizeAmount(mixed $value, string $field, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        $amount = is_int($value) || is_float($value) ? (string) $value : trim((string) $value);

        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $amount) !== 1) {
            $errors[$field] = 'Nominal harus berupa angka positif dengan maksimal dua angka desimal.';

            return null;
        }

        if (bccomp($amount, self::MAX_AMOUNT, 2) > 0) {
            $errors[$field] = 'Nominal melebihi batas yang dapat disimpan.';

            return null;
        }

        return bcadd($amount, '0', 2);
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function audit(
        User $actor,
        string $action,
        JournalEntry $journalEntry,
        ?array $oldValues,
        array $newValues,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        AccessAudit::query()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'subject_type' => JournalEntry::class,
            'subject_id' => $journalEntry->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }
}
