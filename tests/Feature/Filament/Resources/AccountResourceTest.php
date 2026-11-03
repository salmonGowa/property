<?php

namespace Tests\Feature\Filament\Resources;

use App\Enums\AccountType;
use App\Enums\UserRole;
use App\Filament\Resources\Accounts\Pages\ManageAccounts;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\Ledger\JournalLineData;
use App\Services\Ledger\PostingService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AccountResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_finance_manager_creates_an_account(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::FinanceManager)->create());

        Livewire::test(ManageAccounts::class)
            ->callAction('create', [
                'code' => '1140',
                'name' => 'Bank - USD Account',
                'type' => AccountType::Asset->value,
                'is_header' => false,
                'is_active' => true,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('accounts', ['code' => '1140', 'type' => 'asset']);
    }

    public function test_accountant_can_list_accounts_but_not_create_them(): void
    {
        $account = Account::factory()->create();
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());

        Livewire::test(ManageAccounts::class)
            ->assertCanSeeTableRecords([$account])
            ->assertActionHidden('create');
    }

    public function test_account_with_postings_cannot_be_deleted(): void
    {
        $this->travelTo('2026-10-15');
        FiscalPeriod::factory()->forMonth('2026-10-01')->create();
        $used = Account::factory()->create();
        $unused = Account::factory()->create();
        app(PostingService::class)->post('2026-10-01', 'Opening', [
            JournalLineData::debit($used, '10'),
            JournalLineData::credit(Account::factory()->ofType(AccountType::Equity)->create(), '10'),
        ]);
        $this->actingAs(User::factory()->withRole(UserRole::Admin)->create());

        Livewire::test(ManageAccounts::class)
            ->assertActionHidden(TestAction::make('delete')->table($used))
            ->assertActionVisible(TestAction::make('delete')->table($unused));
    }
}
