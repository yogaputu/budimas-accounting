<?php

namespace Tests\Feature\Accounting;

use App\Models\Access\Role;
use App\Models\Access\RoleAssignment;
use App\Models\Accounting\Branch;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\LegalEntity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class JournalEntryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_user_creates_and_posts_a_balanced_manual_journal(): void
    {
        $this->seed();
        $financeUser = $this->createUserWithRole('finance');
        $legalEntity = LegalEntity::query()->where('code', 'BMM')->firstOrFail();
        $cashAccount = $this->account($legalEntity, '1101');
        $revenueAccount = $this->account($legalEntity, '4200');

        $this->withoutVite()
            ->actingAs($financeUser)
            ->get(route('accounting.journals.create', ['legal_entity_id' => $legalEntity->id]))
            ->assertSee('Buat Jurnal Manual')
            ->assertSee('Debit dan kredit');

        $response = $this->actingAs($financeUser)
            ->post(route('accounting.journals.store'), [
                'legal_entity_id' => $legalEntity->id,
                'entry_date' => now()->toDateString(),
                'description' => 'Penerimaan kas manual',
                'lines' => [
                    [
                        'chart_of_account_id' => $cashAccount->id,
                        'memo' => 'Kas diterima',
                        'debit' => '150000.00',
                        'credit' => '0.00',
                    ],
                    [
                        'chart_of_account_id' => $revenueAccount->id,
                        'memo' => 'Pendapatan jasa',
                        'debit' => '0.00',
                        'credit' => '150000.00',
                    ],
                ],
            ]);

        $journalEntry = JournalEntry::query()->firstOrFail();

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirectToRoute('accounting.journals.show', $journalEntry);
        $this->assertDatabaseHas('journal_entries', [
            'id' => $journalEntry->id,
            'status' => JournalEntry::STATUS_DRAFT,
            'total_debit' => '150000',
            'total_credit' => '150000',
        ]);

        $this->actingAs($financeUser)
            ->post(route('accounting.journals.post', $journalEntry))
            ->assertSessionHasNoErrors()
            ->assertRedirectToRoute('accounting.journals.show', $journalEntry);

        $this->assertDatabaseHas('journal_entries', [
            'id' => $journalEntry->id,
            'status' => JournalEntry::STATUS_POSTED,
            'posted_by' => $financeUser->id,
        ]);
    }

    public function test_viewer_is_forbidden_from_the_manual_journal_page(): void
    {
        $this->seed();
        $viewer = $this->createUserWithRole('viewer');

        $this->actingAs($viewer)
            ->get(route('accounting.journals.index'))
            ->assertForbidden();
    }

    private function account(LegalEntity $legalEntity, string $code): ChartOfAccount
    {
        return ChartOfAccount::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('code', $code)
            ->firstOrFail();
    }

    public function test_rejects_description_longer_than_database_column(): void
    {
        $this->seed();
        $financeUser = $this->createUserWithRole('finance');
        $legalEntity = LegalEntity::query()->where('code', 'BMM')->firstOrFail();
        $payload = $this->payload($legalEntity);
        $payload['description'] = str_repeat('a', 256);

        $this->actingAs($financeUser)
            ->post(route('accounting.journals.store'), $payload)
            ->assertSessionHasErrors(['description']);

        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_lines', 0);
        $this->assertDatabaseMissing('access_audits', ['action' => 'journal.draft_created']);
    }

    public function test_branch_actor_creates_views_and_posts_journal_whose_lines_inherit_owned_branch(): void
    {
        $this->seed();
        $financeUser = $this->createUserWithRole('finance');
        $legalEntity = LegalEntity::query()->where('code', 'BMM')->firstOrFail();
        $branch = Branch::factory()->create(['legal_entity_id' => $legalEntity->id]);
        $financeUser->roleAssignments()->update([
            'scope_key' => 'branch',
            'legal_entity_id' => $legalEntity->id,
            'branch_id' => $branch->id,
        ]);
        $payload = $this->payload($legalEntity);
        $payload['branch_id'] = $branch->id;
        $payload['description'] = str_repeat('a', 255);

        $this->actingAs($financeUser)
            ->post(route('accounting.journals.store'), $payload)
            ->assertSessionHasNoErrors();

        $entry = JournalEntry::query()->firstOrFail();
        $this->withoutVite()->get(route('accounting.journals.show', $entry))->assertOk();
        $this->post(route('accounting.journals.post', $entry))
            ->assertSessionHasNoErrors()
            ->assertRedirectToRoute('accounting.journals.show', $entry);
        $this->assertDatabaseHas('journal_entries', [
            'id' => $entry->id,
            'status' => JournalEntry::STATUS_POSTED,
            'description' => str_repeat('a', 255),
        ]);
        $this->assertDatabaseHas('access_audits', ['action' => 'journal.posted', 'subject_id' => $entry->id]);
    }

    public function test_branch_actor_cannot_view_or_post_journal_with_another_branch_line(): void
    {
        $this->seed();
        $financeUser = $this->createUserWithRole('finance');
        $legalEntity = LegalEntity::query()->where('code', 'BMM')->firstOrFail();
        $ownBranch = Branch::factory()->create(['legal_entity_id' => $legalEntity->id]);
        $otherBranch = Branch::factory()->create(['legal_entity_id' => $legalEntity->id]);
        $payload = $this->payload($legalEntity);
        $payload['branch_id'] = $ownBranch->id;
        $payload['lines'][1]['branch_id'] = $otherBranch->id;
        $this->actingAs($financeUser)->post(route('accounting.journals.store'), $payload)->assertSessionHasNoErrors();
        $entry = JournalEntry::query()->firstOrFail();
        $financeUser->roleAssignments()->update([
            'scope_key' => 'branch',
            'legal_entity_id' => $legalEntity->id,
            'branch_id' => $ownBranch->id,
        ]);

        $this->get(route('accounting.journals.show', $entry))->assertForbidden();
        $this->post(route('accounting.journals.post', $entry))->assertForbidden();

        $this->assertSame(JournalEntry::STATUS_DRAFT, $entry->fresh()->status);
        $this->assertDatabaseMissing('access_audits', ['action' => 'journal.posted']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(LegalEntity $legalEntity): array
    {
        return [
            'legal_entity_id' => $legalEntity->id,
            'entry_date' => now()->toDateString(),
            'description' => 'Jurnal audit',
            'lines' => [
                ['chart_of_account_id' => $this->account($legalEntity, '1101')->id, 'debit' => '100.00'],
                ['chart_of_account_id' => $this->account($legalEntity, '4200')->id, 'credit' => '100.00'],
            ],
        ];
    }

    private function createUserWithRole(string $roleCode): User
    {
        $user = User::factory()->create();

        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('code', $roleCode)->firstOrFail()->id,
            'scope_key' => 'global',
            'assigned_at' => now(),
        ]);

        return $user;
    }
}
