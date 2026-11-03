<?php

namespace Tests\Feature\Services\Debt;

use App\Enums\BillingFrequency;
use App\Enums\ChargeType;
use App\Enums\CovenantMetric;
use App\Enums\InterestRateType;
use App\Enums\LoanType;
use App\Enums\RepaymentType;
use App\Models\Lender;
use App\Models\Loan;
use App\Models\Property;
use App\Models\PropertyValuation;
use App\Models\Vendor;
use App\Services\Billing\InvoiceService;
use App\Services\Debt\BadDebtProvisionService;
use App\Services\Debt\CovenantService;
use App\Services\Debt\LoanService;
use App\Services\Debt\NetOperatingIncome;
use App\Services\Ledger\InvalidJournalEntry;
use App\Services\Procurement\SupplierBillService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SetsUpLedger;
use Tests\TestCase;

class CovenantServiceTest extends TestCase
{
    use LazilyRefreshDatabase, SetsUpLedger;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger();
        $lease = $this->draftLease();
        $this->property = $lease->unit->property;

        app(InvoiceService::class)->issue($lease->tenant, '2026-10-01', '2026-10-05', 'Annual rent', [
            ['charge_type' => ChargeType::Rent, 'description' => 'Rent', 'amount' => '1200000', 'vat' => false],
            ['charge_type' => ChargeType::Deposit, 'description' => 'Deposit', 'amount' => '100000', 'vat' => false],
        ], $lease);

        app(SupplierBillService::class)->record(Vendor::factory()->create(), 'B-1', '2026-10-10', null, 'Repairs and a generator', [
            ['account_id' => $this->account('5100')->id, 'description' => 'Repairs', 'amount' => '200000', 'vat' => false],
            ['account_id' => $this->account('1510')->id, 'description' => 'Generator', 'amount' => '900000', 'vat' => false],
        ], property: $this->property);
    }

    public function test_noi_counts_operating_income_and_costs_only(): void
    {
        $noi = app(NetOperatingIncome::class)->between(Carbon::parse('2025-10-16'), Carbon::parse('2026-10-15'), [$this->property->id]);

        $this->assertSame('1200000.00', $noi['income']);
        $this->assertSame('200000.00', $noi['operating_costs']);
        $this->assertSame('1000000.00', $noi['noi']);
        $this->assertSame('0.00', app(NetOperatingIncome::class)->between(Carbon::parse('2025-10-16'), Carbon::parse('2026-10-15'), [Property::factory()->create()->id])['noi']);
    }

    public function test_measures_dscr_ltv_and_debt_yield_against_thresholds(): void
    {
        $loan = $this->drawnLoan('5000000');
        PropertyValuation::factory()->create(['property_id' => $this->property->id, 'market_value' => '10000000', 'valued_on' => '2026-06-30']);
        $service = app(CovenantService::class);
        $on = Carbon::parse('2026-10-15');

        $dscr = $service->measure($loan->covenants()->create(['metric' => CovenantMetric::Dscr, 'threshold' => '1.25']), $on);
        $ltv = $service->measure($loan->covenants()->create(['metric' => CovenantMetric::Ltv, 'threshold' => '60']), $on);
        $debtYield = $service->measure($loan->covenants()->create(['metric' => CovenantMetric::DebtYield, 'threshold' => '15']), $on);

        $this->assertSame(bcdiv('1000000.00', $dscr['inputs']['debt_service'], 3), $dscr['actual']);
        $this->assertFalse($dscr['passed']);
        $this->assertSame('50.000', $ltv['actual']);
        $this->assertTrue($ltv['passed']);
        $this->assertSame('20.000', $debtYield['actual']);
        $this->assertTrue($debtYield['passed']);
    }

    public function test_ltv_cannot_be_measured_without_a_valuation_and_tests_are_recorded(): void
    {
        $loan = $this->drawnLoan('5000000');
        $loan->covenants()->create(['metric' => CovenantMetric::Ltv, 'threshold' => '60']);

        $tests = app(CovenantService::class)->testAll('2026-10-15');

        $this->assertCount(1, $tests);
        $this->assertNull($tests[0]->actual);
        $this->assertNull($tests[0]->passed);
        $this->assertCount(1, app(CovenantService::class)->testAll('2026-10-15'));
        $this->assertSame(1, $loan->covenants()->sole()->tests()->count());
    }

    public function test_bad_debt_provision_follows_the_aged_arrears(): void
    {
        $provisions = app(BadDebtProvisionService::class);

        $first = $provisions->post(BadDebtProvisionService::DEFAULT_RATES, '2026-10-15');

        $this->assertSame('65000.00', $first->required);
        $this->assertSame('65000.00', $this->account('5500')->balance());
        $this->assertSame('-65000.00', $this->account('1210')->balance());

        $released = $provisions->post([...BadDebtProvisionService::DEFAULT_RATES, 'days_1_30' => '2'], '2026-10-16');

        $this->assertSame('-39000.00', $released->adjustment);
        $this->assertSame('-26000.00', $this->account('1210')->balance());
        $this->assertSame('2.00', $provisions->currentRates()['days_1_30']);

        $this->expectException(InvalidJournalEntry::class);
        $provisions->post($provisions->currentRates(), '2026-10-16');
    }

    private function drawnLoan(string $amount): Loan
    {
        $loans = app(LoanService::class);
        $loan = $loans->create([
            'lender_id' => Lender::factory()->create()->id,
            'name' => 'Term loan',
            'type' => LoanType::Mortgage,
            'facility_amount' => $amount,
            'start_date' => '2026-10-01',
            'first_payment_date' => '2026-11-01',
            'maturity_date' => '2031-10-01',
            'payment_frequency' => BillingFrequency::Monthly,
            'repayment_type' => RepaymentType::Annuity,
            'rate_type' => InterestRateType::Fixed,
            'interest_rate' => '12',
        ], [$this->property->id]);
        $loans->drawdown($loan, $amount, '2026-10-01');

        return $loan->refresh();
    }
}
