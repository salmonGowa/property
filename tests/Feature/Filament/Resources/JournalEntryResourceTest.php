<?php

namespace Tests\Feature\Filament\Resources;

use App\Enums\AccountType;
use App\Enums\UserRole;
use App\Filament\Resources\JournalEntries\Pages\CreateJournalEntry;
use App\Filament\Resources\JournalEntries\Pages\ViewJournalEntry;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Ledger\JournalLineData;
use App\Services\Ledger\PostingService;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class JournalEntryResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Account $bank;

    private Account $capital;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-15');
        FiscalPeriod::factory()->forMonth('2026-10-01')->create();
        $this->bank = Account::factory()->ofType(AccountType::Asset)->create(['code' => '1100']);
        $this->capital = Account::factory()->ofType(AccountType::Equity)->create(['code' => '3100']);
    }

    public function test_accountant_posts_a_balanced_entry_from_the_form(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(CreateJournalEntry::class)
            ->fillForm([
                'entry_date' => '2026-10-02',
                'description' => 'Initial capital injection',
                'reference' => 'BANK-001',
                'lines' => [
                    ['account_id' => $this->bank->id, 'debit' => '1000000', 'credit' => null, 'description' => null],
                    ['account_id' => $this->capital->id, 'debit' => null, 'credit' => '1000000', 'description' => null],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $undoRepeaterFake();

        $entry = JournalEntry::query()->sole();
        $this->assertSame('Initial capital injection', $entry->description);
        $this->assertSame('1000000.00', $this->bank->balance());
    }

    public function test_unbalanced_entry_is_not_posted_and_the_user_is_told_why(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(CreateJournalEntry::class)
            ->fillForm([
                'entry_date' => '2026-10-02',
                'description' => 'Typo',
                'lines' => [
                    ['account_id' => $this->bank->id, 'debit' => '1000', 'credit' => null, 'description' => null],
                    ['account_id' => $this->capital->id, 'debit' => null, 'credit' => '100', 'description' => null],
                ],
            ])
            ->call('create')
            ->assertNotified('Entry not posted');

        $undoRepeaterFake();

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_gate_officer_cannot_open_the_journal(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::GateOfficer)->create())
            ->get('/admin/journal-entries')
            ->assertForbidden();
    }

    public function test_auditor_can_view_entries_but_not_create_them(): void
    {
        $entry = $this->postCapital();
        $this->actingAs(User::factory()->withRole(UserRole::Auditor)->create());

        $this->get("/admin/journal-entries/{$entry->id}")->assertOk()->assertSee('JE-000001');
        $this->get('/admin/journal-entries/create')->assertForbidden();
    }

    public function test_finance_manager_reverses_an_entry(): void
    {
        $entry = $this->postCapital();
        $this->actingAs(User::factory()->withRole(UserRole::FinanceManager)->create());

        Livewire::test(ViewJournalEntry::class, ['record' => $entry->id])
            ->callAction('reverse', ['date' => '2026-10-16', 'reason' => 'Duplicate'])
            ->assertHasNoActionErrors();

        $this->assertTrue($entry->reversal()->exists());
        $this->assertSame('0.00', $this->bank->balance());
    }

    public function test_accountant_cannot_reverse_entries(): void
    {
        $entry = $this->postCapital();
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());

        Livewire::test(ViewJournalEntry::class, ['record' => $entry->id])
            ->assertActionHidden('reverse');
    }

    private function postCapital(): JournalEntry
    {
        return app(PostingService::class)->post('2026-10-01', 'Capital', [
            JournalLineData::debit($this->bank, '5000'),
            JournalLineData::credit($this->capital, '5000'),
        ]);
    }
}
