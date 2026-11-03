<?php

namespace Tests\Feature\Services\Debt;

use App\Enums\BillingFrequency;
use App\Enums\InterestRateType;
use App\Enums\LoanStatus;
use App\Enums\LoanType;
use App\Enums\RepaymentType;
use App\Models\Lender;
use App\Models\Loan;
use App\Services\Debt\AmortisationSchedule;
use App\Services\Debt\InterestAccrualService;
use App\Services\Debt\LoanActionRefused;
use App\Services\Debt\LoanService;
use App\Services\Money;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SetsUpLedger;
use Tests\TestCase;

class LoanServiceTest extends TestCase
{
    use LazilyRefreshDatabase, SetsUpLedger;

    private LoanService $loans;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger();
        $this->loans = app(LoanService::class);
    }

    public function test_equal_instalments_follow_the_annuity_formula_and_clear_the_loan(): void
    {
        $rows = app(AmortisationSchedule::class)->build('10000000', '12', BillingFrequency::Monthly, $this->monthlyDates('2026-11-01', 12), Carbon::parse('2026-10-01'), RepaymentType::Annuity);

        $this->assertCount(12, $rows);
        $this->assertSame('101917.81', $rows[0]['interest']);
        $this->assertSame('786570.08', $rows[0]['principal']);
        $this->assertSame('888487.89', $rows[0]['total']);
        $this->assertSame('10000000.00', Money::sum(array_column($rows, 'principal')));
        $this->assertSame('0.00', end($rows)['closing_balance']);
    }

    public function test_grace_periods_and_balloons_shape_the_schedule(): void
    {
        $schedule = app(AmortisationSchedule::class);
        $dates = $this->monthlyDates('2026-11-01', 12);

        $equal = $schedule->build('1200000', '0', BillingFrequency::Monthly, $dates, Carbon::parse('2026-10-01'), RepaymentType::EqualPrincipal, interestOnlyPeriods: 2);
        $this->assertSame(['0.00', '0.00', '120000.00'], array_column(array_slice($equal, 0, 3), 'principal'));

        $bullet = $schedule->build('5000000', '10', BillingFrequency::Monthly, $dates, Carbon::parse('2026-10-01'), RepaymentType::InterestOnly);
        $this->assertSame('0.00', $bullet[10]['principal']);
        $this->assertSame('5000000.00', $bullet[11]['principal']);

        $balloon = $schedule->build('5000000', '12', BillingFrequency::Monthly, $dates, Carbon::parse('2026-10-01'), RepaymentType::Annuity, balloon: '2000000');
        $this->assertGreaterThan(2000000, (float) $balloon[11]['principal']);
        $this->assertLessThan(2300000, (float) $balloon[11]['principal']);
    }

    public function test_drawdown_posts_net_proceeds_and_fees_and_builds_the_schedule(): void
    {
        $loan = $this->loan();

        $this->loans->drawdown($loan, '10000000', '2026-10-01', fees: '100000', reference: 'KCB-DD-1');

        $loan->refresh();
        $this->assertSame(LoanStatus::Active, $loan->status);
        $this->assertSame('10000000.00', $this->account('2500')->balance());
        $this->assertSame('9900000.00', $this->account('1100')->balance());
        $this->assertSame('100000.00', $this->account('5310')->balance());
        $this->assertSame(12, $loan->instalments()->count());
        $this->assertSame('888487.89', $loan->nextInstalment->total);

        $this->expectException(LoanActionRefused::class);
        $this->loans->drawdown($loan, '1', '2026-10-02');
    }

    public function test_paying_an_instalment_splits_principal_and_interest_with_withholding_tax(): void
    {
        $lender = Lender::factory()->create(['name' => 'Mezz Capital', 'interest_withholding_rate' => '15']);
        $loan = $this->loan(['lender_id' => $lender->id, 'type' => LoanType::Mezzanine]);
        $this->loans->drawdown($loan, '10000000', '2026-10-01');

        $repayment = $this->loans->payInstalment($loan->nextInstalment, '2026-11-01', reference: 'EFT-1');

        $this->assertSame('786570.08', $repayment->principal);
        $this->assertSame('15287.67', $repayment->withholding_tax);
        $this->assertSame('873200.22', $repayment->amount_paid);
        $this->assertSame('9213429.92', $this->account('2510')->balance());
        $this->assertSame('101917.81', $this->account('5600')->balance());
        $this->assertSame('15287.67', $this->account('2330')->balance());
        $this->assertSame('9213429.92', $loan->refresh()->outstandingPrincipal());
        $this->assertSame(2, $loan->nextInstalment->sequence);
    }

    public function test_instalments_must_be_paid_in_order(): void
    {
        $loan = $this->loan();
        $this->loans->drawdown($loan, '10000000', '2026-10-01');

        $this->expectException(LoanActionRefused::class);

        $this->loans->payInstalment($loan->instalments()->where('sequence', 2)->sole(), '2026-11-01');
    }

    public function test_prepayment_reduces_the_remaining_instalments(): void
    {
        $loan = $this->loan();
        $this->loans->drawdown($loan, '10000000', '2026-10-01');
        $before = (float) $loan->nextInstalment->total;

        $this->loans->prepay($loan, '4000000', '2026-10-15', reference: 'EFT-PP');

        $loan->refresh();
        $this->assertSame('6000000.00', $loan->outstandingPrincipal());
        $this->assertLessThan($before * 0.7, (float) $loan->nextInstalment->total);
        $this->assertSame(12, $loan->instalments()->count());
    }

    public function test_variable_rate_change_reprices_unpaid_instalments(): void
    {
        $loan = $this->loan(['rate_type' => InterestRateType::Variable, 'base_rate_name' => 'CBK Rate', 'base_rate' => '9.000', 'margin' => '3.000']);
        $this->loans->drawdown($loan, '10000000', '2026-10-01');
        $this->assertSame('CBK Rate 9% + 3% = 12%', $loan->refresh()->rateDescription());

        $this->loans->changeRate($loan, '2026-10-10', baseRate: '10.000', reason: 'MPC raised the CBK Rate');

        $loan->refresh();
        $this->assertSame('13.000', $loan->interest_rate);
        $this->assertSame('110410.96', $loan->nextInstalment->interest);
        $this->assertSame('12.000', $loan->rateChanges()->sole()->previous_rate);
    }

    public function test_repaying_everything_closes_the_loan(): void
    {
        $loan = $this->loan();
        $this->loans->drawdown($loan, '10000000', '2026-10-01');

        $this->loans->prepay($loan, '10000000', '2026-10-10');

        $loan->refresh();
        $this->assertSame(LoanStatus::Closed, $loan->status);
        $this->assertSame(0, $loan->instalments()->count());
    }

    public function test_month_end_interest_is_accrued_and_reversed_once(): void
    {
        $loan = $this->loan();
        $this->loans->drawdown($loan, '10000000', '2026-10-01');

        $result = app(InterestAccrualService::class)->accrue('2026-10-31');

        $this->assertCount(1, $result['accruals']);
        $this->assertSame(30, $result['accruals'][0]->days);
        $this->assertSame('98630.14', $result['accruals'][0]->amount);
        $this->assertSame('0.00', $this->account('2410')->balance());
        $this->assertSame('98630.14', $this->account('2410')->balance(Carbon::parse('2026-10-31')));
        $this->assertCount(0, app(InterestAccrualService::class)->accrue('2026-10-31')['accruals']);
    }

    public function test_rejects_terms_that_do_not_make_sense(): void
    {
        $this->expectException(LoanActionRefused::class);

        $this->loan(['first_payment_date' => '2026-09-01']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function loan(array $overrides = []): Loan
    {
        return $this->loans->create([
            'lender_id' => Lender::factory()->create()->id,
            'name' => 'Westlands Business Park term loan',
            'type' => LoanType::SeniorTerm,
            'facility_amount' => '10000000',
            'start_date' => '2026-10-01',
            'first_payment_date' => '2026-11-01',
            'maturity_date' => '2027-10-01',
            'payment_frequency' => BillingFrequency::Monthly,
            'repayment_type' => RepaymentType::Annuity,
            'rate_type' => InterestRateType::Fixed,
            'interest_rate' => '12',
            ...$overrides,
        ]);
    }

    /**
     * @return list<Carbon>
     */
    private function monthlyDates(string $first, int $count): array
    {
        return array_map(fn (int $i): Carbon => Carbon::parse($first)->addMonthsNoOverflow($i), range(0, $count - 1));
    }
}
