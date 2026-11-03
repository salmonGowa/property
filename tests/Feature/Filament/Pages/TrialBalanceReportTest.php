<?php

namespace Tests\Feature\Filament\Pages;

use App\Enums\AccountType;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\Ledger\JournalLineData;
use App\Services\Ledger\PostingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TrialBalanceReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_auditor_sees_balances_and_totals(): void
    {
        $this->travelTo('2026-10-15');
        FiscalPeriod::factory()->forMonth('2026-10-01')->create();
        $bank = Account::factory()->ofType(AccountType::Asset)->create(['code' => '1100', 'name' => 'Bank - Operating Account']);
        $capital = Account::factory()->ofType(AccountType::Equity)->create(['code' => '3100', 'name' => 'Share Capital']);
        app(PostingService::class)->post('2026-10-01', 'Capital', [
            JournalLineData::debit($bank, '250000'),
            JournalLineData::credit($capital, '250000'),
        ]);

        $this->actingAs(User::factory()->withRole(UserRole::Auditor)->create())
            ->get('/admin/trial-balance-report')
            ->assertOk()
            ->assertSeeInOrder(['Bank - Operating Account', '250,000.00', 'Share Capital', '250,000.00', 'Total', '250,000.00', '250,000.00']);
    }

    public function test_property_manager_cannot_open_the_report(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::PropertyManager)->create())
            ->get('/admin/trial-balance-report')
            ->assertForbidden();
    }
}
