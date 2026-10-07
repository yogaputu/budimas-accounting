<?php

namespace Tests\Feature\Accounting;

use App\Accounting\JournalEntryService;
use App\Models\Access\Role;
use App\Models\Access\RoleAssignment;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\Branch;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\Accounting\LegalEntity;
use App\Models\User;
use Database\Seeders\Access\AccessControlSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

final class JournalEntryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_balanced_manual_drafts_with_sequential_entity_numbers_and_an_audit_trail(): void
    {
        $context = $this->journalContext();

        $firstEntry = $this->service()->createDraft(
            $this->balancedPayload($context['legalEntity']->id, $context['debitAccount']->id, $context['creditAccount']->id),
            $context['actor'],
            '127.0.0.1',
            'PHPUnit',
        );
        $secondEntry = $this->service()->createDraft(
            $this->balancedPayload($context['legalEntity']->id, $context['debitAccount']->id, $context['creditAccount']->id),
            $context['actor'],
        );

        $this->assertModelExists($firstEntry);
        $this->assertSame('JV-BMM-202609-00001', $firstEntry->entry_no);
        $this->assertSame('100000.00', (string) $firstEntry->total_debit);
        $this->assertSame('100000.00', (string) $firstEntry->total_credit);
        $this->assertTrue($firstEntry->isDraft());
        $this->assertSame('JV-BMM-202609-00002', $secondEntry->entry_no);
        $this->assertDatabaseCount('journal_lines', 4);
        $this->assertDatabaseHas('access_audits', [
            'actor_id' => $context['actor']->id,
            'action' => 'journal.draft_created',
            'subject_type' => JournalEntry::class,
            'subject_id' => $firstEntry->id,
        ]);
    }

    public function test_rejects_unbalanced_draft_without_creating_a_journal(): void
    {
        $context = $this->journalContext();
        $payload = $this->balancedPayload(
            $context['legalEntity']->id,
            $context['debitAccount']->id,
            $context['creditAccount']->id,
        );
        $payload['lines'][1]['credit'] = '99999.99';

        try {
            $this->service()->createDraft($payload, $context['actor']);
            $this->fail('Unbalanced journal draft was created.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_lines', 0);
    }

    public function test_rejects_an_account_from_a_different_legal_entity(): void
    {
        $context = $this->journalContext();
        $otherEntity = LegalEntity::query()->create([
            'code' => 'TMP',
            'name' => 'PT Tirta Makmur Perkasa',
            'is_active' => true,
        ]);
        $otherEntityAccount = ChartOfAccount::query()->create([
            'legal_entity_id' => $otherEntity->id,
            'code' => '1101',
            'name' => 'Kas TMP',
            'category' => 'asset',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'is_active' => true,
        ]);
        $payload = $this->balancedPayload(
            $context['legalEntity']->id,
            $otherEntityAccount->id,
            $context['creditAccount']->id,
        );

        try {
            $this->service()->createDraft($payload, $context['actor']);
            $this->fail('A cross-entity account was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.0.chart_of_account_id', $exception->errors());
        }

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_rejects_entry_date_outside_an_open_accounting_period(): void
    {
        $context = $this->journalContext();
        $payload = $this->balancedPayload(
            $context['legalEntity']->id,
            $context['debitAccount']->id,
            $context['creditAccount']->id,
        );
        $payload['entry_date'] = '2026-10-01';

        try {
            $this->service()->createDraft($payload, $context['actor']);
            $this->fail('Journal was created outside an open accounting period.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('entry_date', $exception->errors());
        }

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_posts_only_a_balanced_draft_and_locks_the_posted_entry_and_its_lines(): void
    {
        $context = $this->journalContext();
        $journalEntry = $this->service()->createDraft(
            $this->balancedPayload($context['legalEntity']->id, $context['debitAccount']->id, $context['creditAccount']->id),
            $context['actor'],
        );

        JournalLine::query()
            ->where('journal_entry_id', $journalEntry->id)
            ->where('credit', '>', 0)
            ->update(['credit' => '99999.99']);

        try {
            $this->service()->post($journalEntry, $context['actor']);
            $this->fail('Unbalanced draft was posted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $this->assertSame(JournalEntry::STATUS_DRAFT, $journalEntry->fresh()->status);

        JournalLine::query()
            ->where('journal_entry_id', $journalEntry->id)
            ->where('credit', '>', 0)
            ->update(['credit' => '100000.00']);

        $postedEntry = $this->service()->post($journalEntry->fresh(), $context['actor']);

        $this->assertTrue($postedEntry->isPosted());
        $this->assertSame($context['actor']->id, $postedEntry->posted_by);
        $this->assertDatabaseHas('access_audits', [
            'actor_id' => $context['actor']->id,
            'action' => 'journal.posted',
            'subject_type' => JournalEntry::class,
            'subject_id' => $postedEntry->id,
        ]);

        try {
            $postedEntry->update(['description' => 'Perubahan tidak boleh terjadi']);
            $this->fail('Posted journal entry was changed.');
        } catch (LogicException) {
            $this->assertSame(JournalEntry::STATUS_POSTED, $postedEntry->fresh()->status);
        }

        try {
            $postedEntry->lines()->firstOrFail()->update(['memo' => 'Perubahan tidak boleh terjadi']);
            $this->fail('Posted journal line was changed.');
        } catch (LogicException) {
            $this->assertDatabaseHas('journal_lines', [
                'journal_entry_id' => $postedEntry->id,
                'memo' => 'Kas masuk',
            ]);
        }
    }

    private function service(): JournalEntryService
    {
        return app(JournalEntryService::class);
    }

    public function test_rejects_invalid_header_branch_without_creating_a_draft(): void
    {
        $context = $this->journalContext();
        $payload = $this->balancedPayload($context['legalEntity']->id, $context['debitAccount']->id, $context['creditAccount']->id);
        $payload['branch_id'] = 'invalid';

        try {
            $this->service()->createDraft($payload, $context['actor']);
            $this->fail('Invalid branch was silently discarded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('branch_id', $exception->errors());
        }

        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('access_audits', 0);
    }

    public function test_rejects_line_amount_beyond_database_decimal_capacity(): void
    {
        $context = $this->journalContext();
        $payload = $this->balancedPayload($context['legalEntity']->id, $context['debitAccount']->id, $context['creditAccount']->id);
        $payload['lines'][0]['debit'] = '1000000000000000000.00';
        $payload['lines'][1]['credit'] = '1000000000000000000.00';

        try {
            $this->service()->createDraft($payload, $context['actor']);
            $this->fail('An overflowing amount was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.0.debit', $exception->errors());
        }

        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_lines', 0);
        $this->assertDatabaseCount('access_audits', 0);
    }

    public function test_rejects_balanced_totals_beyond_database_decimal_capacity(): void
    {
        $context = $this->journalContext();
        $payload = $this->balancedPayload($context['legalEntity']->id, $context['debitAccount']->id, $context['creditAccount']->id);
        $payload['lines'][0]['debit'] = '600000000000000000.00';
        $payload['lines'][1]['credit'] = '600000000000000000.00';
        $payload['lines'] = [...$payload['lines'], ...$payload['lines']];

        try {
            $this->service()->createDraft($payload, $context['actor']);
            $this->fail('Overflowing journal totals were accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_lines', 0);
    }

    public function test_rejects_posting_after_legal_entity_is_deactivated(): void
    {
        $context = $this->journalContext();
        $entry = $this->service()->createDraft(
            $this->balancedPayload($context['legalEntity']->id, $context['debitAccount']->id, $context['creditAccount']->id),
            $context['actor'],
        );
        $context['legalEntity']->update(['is_active' => false]);

        try {
            $this->service()->post($entry, $context['actor']);
            $this->fail('A journal for an inactive company was posted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('legal_entity_id', $exception->errors());
        }

        $this->assertSame(JournalEntry::STATUS_DRAFT, $entry->fresh()->status);
        $this->assertDatabaseMissing('access_audits', ['action' => 'journal.posted']);
    }

    public function test_branch_actor_cannot_create_lines_for_another_branch(): void
    {
        $context = $this->journalContext();
        $ownBranch = Branch::factory()->create(['legal_entity_id' => $context['legalEntity']->id]);
        $otherBranch = Branch::factory()->create(['legal_entity_id' => $context['legalEntity']->id]);
        $context['actor']->roleAssignments()->update([
            'scope_key' => 'branch',
            'legal_entity_id' => $context['legalEntity']->id,
            'branch_id' => $ownBranch->id,
        ]);
        $payload = $this->balancedPayload($context['legalEntity']->id, $context['debitAccount']->id, $context['creditAccount']->id);
        $payload['branch_id'] = $ownBranch->id;
        $payload['lines'][1]['branch_id'] = $otherBranch->id;

        try {
            $this->service()->createDraft($payload, $context['actor']);
            $this->fail('Cross-branch journal lines were accepted.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('journal_entries', 0);
            $this->assertDatabaseCount('journal_lines', 0);
            $this->assertDatabaseCount('access_audits', 0);
        }
    }

    /**
     * @return array{
     *     actor: User,
     *     legalEntity: LegalEntity,
     *     accountingPeriod: AccountingPeriod,
     *     debitAccount: ChartOfAccount,
     *     creditAccount: ChartOfAccount
     * }
     */
    private function journalContext(): array
    {
        $this->seed(AccessControlSeeder::class);

        $actor = User::factory()->create();
        $legalEntity = LegalEntity::query()->create([
            'code' => 'BMM',
            'name' => 'PT Budimas Makmur Mulia',
            'is_active' => true,
        ]);
        $accountingPeriod = AccountingPeriod::query()->create([
            'legal_entity_id' => $legalEntity->id,
            'code' => '2026-09',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
        ]);
        $debitAccount = ChartOfAccount::query()->create([
            'legal_entity_id' => $legalEntity->id,
            'code' => '1101',
            'name' => 'Kas',
            'category' => 'asset',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'is_active' => true,
        ]);
        $creditAccount = ChartOfAccount::query()->create([
            'legal_entity_id' => $legalEntity->id,
            'code' => '4101',
            'name' => 'Pendapatan Jasa',
            'category' => 'revenue',
            'normal_balance' => 'credit',
            'is_postable' => true,
            'is_active' => true,
        ]);

        RoleAssignment::query()->create([
            'user_id' => $actor->id,
            'role_id' => Role::query()->where('code', 'finance')->value('id'),
            'scope_key' => 'global',
            'assigned_at' => now(),
        ]);

        return compact('accountingPeriod', 'actor', 'creditAccount', 'debitAccount', 'legalEntity');
    }

    /**
     * @return array{
     *     legal_entity_id: int,
     *     entry_date: string,
     *     description: string,
     *     lines: list<array{
     *         chart_of_account_id: int,
     *         memo: string,
     *         debit: string,
     *         credit: string
     *     }>
     * }
     */
    private function balancedPayload(int $legalEntityId, int $debitAccountId, int $creditAccountId): array
    {
        return [
            'legal_entity_id' => $legalEntityId,
            'entry_date' => '2026-09-24',
            'description' => 'Penerimaan pendapatan jasa',
            'lines' => [
                [
                    'chart_of_account_id' => $debitAccountId,
                    'memo' => 'Kas masuk',
                    'debit' => '100000.00',
                    'credit' => '0.00',
                ],
                [
                    'chart_of_account_id' => $creditAccountId,
                    'memo' => 'Pendapatan jasa',
                    'debit' => '0.00',
                    'credit' => '100000.00',
                ],
            ],
        ];
    }
}
