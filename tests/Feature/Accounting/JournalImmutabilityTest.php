<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\LegalEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

final class JournalImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_draft_instance_cannot_change_a_posted_journal(): void
    {
        $draft = $this->draft();
        $draft->fresh()->update(['status' => JournalEntry::STATUS_POSTED]);

        try {
            $draft->update(['description' => 'Forbidden change']);
            $this->fail('A stale draft instance changed a posted journal.');
        } catch (LogicException $exception) {
            $this->assertSame('Jurnal yang telah diposting tidak dapat diubah.', $exception->getMessage());
        }

        $this->assertDatabaseHas('journal_entries', [
            'id' => $draft->id,
            'status' => JournalEntry::STATUS_POSTED,
            'description' => 'Original description',
        ]);
    }

    public function test_stale_draft_instance_cannot_delete_a_posted_journal(): void
    {
        $draft = $this->draft();
        $draft->fresh()->update(['status' => JournalEntry::STATUS_POSTED]);

        try {
            $draft->delete();
            $this->fail('A stale draft instance deleted a posted journal.');
        } catch (LogicException $exception) {
            $this->assertSame('Jurnal yang telah diposting tidak dapat dihapus.', $exception->getMessage());
        }

        $this->assertModelExists($draft);
        $this->assertDatabaseCount('journal_lines', 2);
    }

    public function test_changing_the_in_memory_status_cannot_delete_a_posted_journal(): void
    {
        $entry = $this->draft();
        $entry->update(['status' => JournalEntry::STATUS_POSTED]);
        $entry->status = JournalEntry::STATUS_DRAFT;

        try {
            $entry->delete();
            $this->fail('Changing the in-memory status allowed deletion of a posted journal.');
        } catch (LogicException $exception) {
            $this->assertSame('Jurnal yang telah diposting tidak dapat dihapus.', $exception->getMessage());
        }

        $this->assertDatabaseHas('journal_entries', [
            'id' => $entry->id,
            'status' => JournalEntry::STATUS_POSTED,
        ]);
        $this->assertDatabaseCount('journal_lines', 2);
    }

    public function test_posted_line_cannot_be_moved_to_a_draft_journal(): void
    {
        $entry = $this->draft();
        $line = $entry->lines()->firstOrFail();
        $entry->update(['status' => JournalEntry::STATUS_POSTED]);
        $otherDraft = $this->draft();

        try {
            $line->update(['journal_entry_id' => $otherDraft->id, 'line_no' => 3]);
            $this->fail('A posted journal line was moved to a draft.');
        } catch (LogicException $exception) {
            $this->assertSame('Baris jurnal yang telah diposting tidak dapat diubah.', $exception->getMessage());
        }

        $this->assertDatabaseHas('journal_lines', [
            'id' => $line->id,
            'journal_entry_id' => $entry->id,
            'line_no' => 1,
        ]);
    }

    public function test_changing_the_in_memory_parent_cannot_delete_a_posted_line(): void
    {
        $entry = $this->draft();
        $line = $entry->lines()->firstOrFail();
        $entry->update(['status' => JournalEntry::STATUS_POSTED]);
        $line->journal_entry_id = $this->draft()->id;

        try {
            $line->delete();
            $this->fail('A posted line was deleted after changing its in-memory parent.');
        } catch (LogicException $exception) {
            $this->assertSame('Baris jurnal yang telah diposting tidak dapat diubah.', $exception->getMessage());
        }

        $this->assertDatabaseHas('journal_lines', [
            'id' => $line->id,
            'journal_entry_id' => $entry->id,
        ]);
    }

    public function test_draft_journal_and_lines_remain_mutable(): void
    {
        $draft = $this->draft();
        $line = $draft->lines()->firstOrFail();

        $draft->update(['description' => 'Updated draft']);
        $line->update(['memo' => 'Updated memo']);

        $this->assertDatabaseHas('journal_entries', [
            'id' => $draft->id,
            'description' => 'Updated draft',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'id' => $line->id,
            'memo' => 'Updated memo',
        ]);

        $line->delete();
        $this->assertModelMissing($line);

        $draft->delete();
        $this->assertModelMissing($draft);
        $this->assertDatabaseCount('journal_lines', 0);
    }

    private function draft(): JournalEntry
    {
        $entity = LegalEntity::factory()->create();
        $period = AccountingPeriod::factory()->for($entity)->create([
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
        ]);
        $account = ChartOfAccount::factory()->for($entity)->create();
        $entry = JournalEntry::query()->create([
            'legal_entity_id' => $entity->id,
            'accounting_period_id' => $period->id,
            'entry_no' => 'JV-TEST-00001',
            'entry_date' => '2026-09-24',
            'status' => JournalEntry::STATUS_DRAFT,
            'description' => 'Original description',
            'total_debit' => '100.00',
            'total_credit' => '100.00',
        ]);
        $entry->lines()->createMany([
            [
                'chart_of_account_id' => $account->id,
                'line_no' => 1,
                'debit' => '100.00',
                'credit' => '0.00',
            ],
            [
                'chart_of_account_id' => $account->id,
                'line_no' => 2,
                'debit' => '0.00',
                'credit' => '100.00',
            ],
        ]);

        return $entry;
    }
}
