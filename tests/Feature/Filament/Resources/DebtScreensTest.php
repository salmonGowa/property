<?php

namespace Tests\Feature\Filament\Resources;

use App\Enums\BillingFrequency;
use App\Enums\CovenantMetric;
use App\Enums\InterestRateType;
use App\Enums\LoanStatus;
use App\Enums\LoanType;
use App\Enums\RepaymentType;
use App\Enums\UserRole;
use App\Filament\Pages\BadDebtProvisions;
use App\Filament\Pages\DebtPortfolioReport;
use App\Filament\Resources\Lenders\Pages\ManageLenders;
use App\Filament\Resources\Loans\Pages\CreateLoan;
use App\Filament\Resources\Loans\Pages\EditLoan;
use App\Filament\Resources\Loans\Pages\ListLoans;
use App\Filament\Resources\Loans\Pages\ViewLoan;
use App\Filament\Resources\Loans\RelationManagers\CovenantsRelationManager;
use App\Filament\Resources\Loans\RelationManagers\InstalmentsRelationManager;
use App\Filament\Resources\Properties\Pages\ViewProperty;
use App\Filament\Resources\Properties\RelationManagers\ValuationsRelationManager;
use App\Models\BadDebtProvision;
use App\Models\Lender;
use App\Models\Loan;
use App\Models\Property;
use App\Models\PropertyValuation;
use App\Models\User;
use App\Services\Debt\LoanService;
use App\Services\Leasing\LeaseService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SetsUpLedger;
use Tests\TestCase;

class DebtScreensTest extends TestCase
{
    use LazilyRefreshDatabase, SetsUpLedger;

    private Lender $lender;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger();
        $this->lender = Lender::factory()->create(['name' => 'KCB Bank Kenya']);
    }

    public function test_finance_manager_sets_up_a_loan_with_a_live_schedule_preview(): void
    {
        $property = Property::factory()->create();
        $this->actingAs(User::factory()->withRole(UserRole::FinanceManager)->create());

        Livewire::test(CreateLoan::class)
            ->fillForm([
                'lender_id' => $this->lender->id,
                'name' => 'Kilimani Heights mortgage',
                'type' => 'mortgage',
                'facility_amount' => '10000000',
                'properties' => [$property->id],
                'rate_type' => 'variable',
                'base_rate_name' => 'CBK Rate',
                'base_rate' => '9.5',
                'margin' => '3.5',
                'start_date' => '2026-10-01',
                'first_payment_date' => '2026-11-01',
                'maturity_date' => '2027-10-01',
                'payment_frequency' => 'monthly',
                'repayment_type' => 'annuity',
                'interest_only_periods' => 0,
                'balloon_amount' => 0,
            ])
            ->assertSee('12 instalments, monthly, from 1 Nov 2026')
            ->call('create')
            ->assertHasNoFormErrors();

        $loan = Loan::query()->sole();
        $this->assertSame('13.000', $loan->interest_rate);
        $this->assertSame(LoanStatus::Pending, $loan->status);
        $this->assertTrue($loan->properties->first()->is($property));
        $this->assertSame('2500', $loan->liabilityAccount->code);
    }

    public function test_drawdown_payment_and_rate_change_from_the_loan_page(): void
    {
        $loan = $this->loan();
        $this->actingAs(User::factory()->withRole(UserRole::FinanceManager)->create());

        Livewire::test(ViewLoan::class, ['record' => $loan->id])
            ->callAction('drawdown', ['amount' => '10000000', 'fees' => '0', 'drawn_on' => '2026-10-01', 'bank_account_id' => $this->account('1100')->id])
            ->assertNotified('Drawdown recorded');

        $loan->refresh();
        $this->travelTo('2026-11-01');

        Livewire::test(ViewLoan::class, ['record' => $loan->id])
            ->assertSee('Principal owed')
            ->callAction('payInstalment', ['principal' => '786570.08', 'interest' => '101917.81', 'paid_on' => '2026-11-01', 'bank_account_id' => $this->account('1100')->id, 'reference' => 'FT2611'])
            ->assertNotified('Instalment paid')
            ->callAction('changeRate', ['effective_from' => '2026-11-01', 'interest_rate' => '13', 'reason' => 'Repricing'])
            ->assertNotified('Interest rate updated');

        $loan->refresh();
        $this->assertSame('9213429.92', $loan->outstandingPrincipal());
        $this->assertSame('13.000', $loan->interest_rate);
        Livewire::test(InstalmentsRelationManager::class, ['ownerRecord' => $loan, 'pageClass' => ViewLoan::class])
            ->assertOk()
            ->assertSee('Paid 1 Nov 2026');
    }

    public function test_accountants_view_loans_but_cannot_transact(): void
    {
        $loan = $this->loan();
        app(LoanService::class)->drawdown($loan, '10000000', '2026-10-01');
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());

        Livewire::test(ListLoans::class)->assertOk()->assertCanSeeTableRecords([$loan])->assertActionHidden('create');
        Livewire::test(ViewLoan::class, ['record' => $loan->id])
            ->assertOk()
            ->assertActionHidden('payInstalment')
            ->assertActionHidden('drawdown');
    }

    public function test_covenants_are_added_and_shown_live_on_the_portfolio_report(): void
    {
        $property = Property::factory()->create();
        $loan = $this->loan([$property->id]);
        app(LoanService::class)->drawdown($loan, '10000000', '2026-10-01');
        PropertyValuation::factory()->create(['property_id' => $property->id, 'market_value' => '20000000']);
        $this->actingAs(User::factory()->withRole(UserRole::FinanceManager)->create());

        Livewire::test(CovenantsRelationManager::class, ['ownerRecord' => $loan, 'pageClass' => ViewLoan::class])
            ->callTableAction('create', data: ['metric' => CovenantMetric::Ltv->value, 'threshold' => '60', 'is_active' => true])
            ->assertHasNoTableActionErrors();

        Livewire::test(DebtPortfolioReport::class)
            ->assertOk()
            ->assertSee('50.0% vs 60.0%');
        Livewire::test(ViewLoan::class, ['record' => $loan->id])->assertSee('LTV at most 60.0%')->assertSee('Met');
    }

    public function test_valuations_are_recorded_on_the_property(): void
    {
        $property = Property::factory()->create();
        $this->actingAs(User::factory()->withRole(UserRole::FinanceManager)->create());

        Livewire::test(ValuationsRelationManager::class, ['ownerRecord' => $property, 'pageClass' => ViewProperty::class])
            ->callTableAction('create', data: ['valued_on' => '2026-09-30', 'market_value' => '350000000', 'valuer' => 'Acme Valuers'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('350000000.00', $property->latestValuation->market_value);
    }

    public function test_finance_manager_posts_a_bad_debt_provision(): void
    {
        $lease = $this->draftLease(['start_date' => '2026-09-01']);
        app(LeaseService::class)->activate($lease);
        $this->actingAs(User::factory()->withRole(UserRole::FinanceManager)->create());

        Livewire::test(BadDebtProvisions::class)
            ->assertOk()
            ->callAction('provide', ['rates' => ['current' => '0', 'days_1_30' => '10', 'days_31_60' => '25', 'days_61_90' => '50', 'days_over_90' => '100'], 'as_of' => '2026-10-15'])
            ->assertNotified();

        $this->assertTrue(BadDebtProvision::query()->sole()->adjustment > 0);
    }

    public function test_debt_pages_render(): void
    {
        $loan = $this->loan();
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageLenders::class)->assertOk()->assertSee('KCB Bank Kenya');
        Livewire::test(EditLoan::class, ['record' => $loan->id])->assertOk();
        Livewire::test(ViewLoan::class, ['record' => $loan->id])->assertOk()->assertActionVisible('drawdown');
    }

    /**
     * @param  list<int>  $propertyIds
     */
    private function loan(array $propertyIds = []): Loan
    {
        return app(LoanService::class)->create([
            'lender_id' => $this->lender->id,
            'name' => 'Term loan',
            'type' => LoanType::SeniorTerm,
            'facility_amount' => '10000000',
            'start_date' => '2026-10-01',
            'first_payment_date' => '2026-11-01',
            'maturity_date' => '2027-10-01',
            'payment_frequency' => BillingFrequency::Monthly,
            'repayment_type' => RepaymentType::Annuity,
            'rate_type' => InterestRateType::Fixed,
            'interest_rate' => '12',
        ], $propertyIds);
    }
}
