<?php

namespace Tests\Feature\Filament\Resources;

use App\Enums\FiscalPeriodStatus;
use App\Enums\UserRole;
use App\Filament\Resources\FiscalPeriods\Pages\ManageFiscalPeriods;
use App\Models\FiscalPeriod;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FiscalPeriodResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_finance_manager_opens_a_year_of_monthly_periods(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::FinanceManager)->create());

        Livewire::test(ManageFiscalPeriods::class)
            ->callAction('openYear', ['year' => 2027]);

        $this->assertSame(12, FiscalPeriod::query()->whereYear('starts_on', 2027)->count());
    }

    public function test_finance_manager_closes_a_period(): void
    {
        $manager = User::factory()->withRole(UserRole::FinanceManager)->create();
        $period = FiscalPeriod::factory()->forMonth('2026-09-01')->create();
        $this->actingAs($manager);

        Livewire::test(ManageFiscalPeriods::class)
            ->callAction(TestAction::make('close')->table($period));

        $period->refresh();
        $this->assertSame(FiscalPeriodStatus::Closed, $period->status);
        $this->assertSame($manager->id, $period->closed_by);
    }

    public function test_only_admin_can_reopen_a_closed_period(): void
    {
        $period = FiscalPeriod::factory()->forMonth('2026-09-01')->closed()->create();

        $this->actingAs(User::factory()->withRole(UserRole::FinanceManager)->create());
        Livewire::test(ManageFiscalPeriods::class)
            ->assertActionHidden(TestAction::make('reopen')->table($period));

        $this->actingAs(User::factory()->withRole(UserRole::Admin)->create());
        Livewire::test(ManageFiscalPeriods::class)
            ->callAction(TestAction::make('reopen')->table($period));

        $this->assertTrue($period->refresh()->isOpen());
    }

    public function test_accountant_cannot_close_or_open_periods(): void
    {
        $period = FiscalPeriod::factory()->forMonth('2026-09-01')->create();
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());

        Livewire::test(ManageFiscalPeriods::class)
            ->assertActionHidden('openYear')
            ->assertActionHidden(TestAction::make('close')->table($period));
    }
}
