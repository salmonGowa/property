<?php

namespace Tests\Feature\Services\Leasing;

use App\Enums\BillingFrequency;
use App\Models\Lease;
use App\Services\Leasing\RentSchedule;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RentScheduleTest extends TestCase
{
    private RentSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schedule = new RentSchedule;
    }

    public function test_monthly_lease_has_one_full_period_per_month(): void
    {
        $lease = $this->lease(['start_date' => '2026-01-01']);

        $periods = $this->schedule->periodsUpTo($lease, Carbon::parse('2026-03-15'));

        $this->assertSame(['2026-01-01', '2026-02-01', '2026-03-01'], array_map(fn ($period) => $period->start->toDateString(), $periods));
        $this->assertSame('30000.00', $this->schedule->rentFor($lease, $periods[1]));
    }

    public function test_mid_month_start_prorates_the_first_period_by_days(): void
    {
        $lease = $this->lease(['start_date' => '2026-10-15', 'service_charge' => '3100.00']);

        [$first] = $this->schedule->periodsUpTo($lease, Carbon::parse('2026-10-31'));

        $this->assertSame('2026-10-15', $first->billedFrom->toDateString());
        $this->assertSame(17, $first->billedDays());
        $this->assertSame('16451.61', $this->schedule->rentFor($lease, $first));
        $this->assertSame('1700.00', $this->schedule->serviceChargeFor($lease, $first));
    }

    public function test_quarterly_lease_bills_three_months_per_period(): void
    {
        $lease = $this->lease(['start_date' => '2026-01-01', 'billing_frequency' => BillingFrequency::Quarterly]);

        $periods = $this->schedule->periodsUpTo($lease, Carbon::parse('2026-06-30'));

        $this->assertSame(['2026-01-01', '2026-04-01'], array_map(fn ($period) => $period->start->toDateString(), $periods));
        $this->assertSame('2026-03-31', $periods[0]->end->toDateString());
        $this->assertSame('90000.00', $this->schedule->rentFor($lease, $periods[0]));
    }

    public function test_rent_escalates_and_compounds_at_each_interval(): void
    {
        $lease = $this->lease(['start_date' => '2026-01-01', 'escalation_rate' => '10.00', 'escalation_interval_months' => 12]);

        $this->assertSame('30000.00', $this->schedule->monthlyRentOn($lease, Carbon::parse('2026-12-01')));
        $this->assertSame('33000.00', $this->schedule->monthlyRentOn($lease, Carbon::parse('2027-01-01')));
        $this->assertSame('36300.00', $this->schedule->monthlyRentOn($lease, Carbon::parse('2028-01-01')));
    }

    public function test_billing_stops_at_termination_and_prorates_the_last_period(): void
    {
        $lease = $this->lease(['start_date' => '2026-01-01', 'terminated_on' => '2026-02-14']);

        $periods = $this->schedule->periodsUpTo($lease, Carbon::parse('2026-06-01'));

        $this->assertCount(2, $periods);
        $this->assertSame('15000.00', $this->schedule->rentFor($lease, $periods[1]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lease(array $attributes): Lease
    {
        return new Lease([
            'billing_frequency' => BillingFrequency::Monthly,
            'rent_amount' => '30000.00',
            'service_charge' => '0.00',
            'escalation_rate' => '0.00',
            'escalation_interval_months' => 12,
            ...$attributes,
        ]);
    }
}
