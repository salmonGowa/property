<?php

namespace App\Services\Leasing;

use App\Models\Lease;
use App\Services\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Works out a lease's billing periods and what is charged for each of them.
 *
 * Periods are aligned to calendar months starting from the month the lease starts,
 * in blocks of the billing frequency (1, 3, 6 or 12 months). A period the tenant only
 * occupies for part of is prorated by days.
 */
class RentSchedule
{
    /**
     * Billing periods that start on or before the given date and overlap the occupancy.
     *
     * @return list<BillingPeriod>
     */
    public function periodsUpTo(Lease $lease, CarbonInterface $upTo): array
    {
        $months = $lease->billing_frequency->months();
        $occupancyStart = CarbonImmutable::parse($lease->start_date);
        $occupancyEnd = $lease->occupancyEndsOn() ? CarbonImmutable::parse($lease->occupancyEndsOn()) : null;
        $start = $occupancyStart->startOfMonth();
        $periods = [];

        while ($start->lte($upTo) && ($occupancyEnd === null || $start->lte($occupancyEnd))) {
            $end = $start->addMonthsNoOverflow($months)->subDay();
            $periods[] = new BillingPeriod(
                $start,
                $end,
                $occupancyStart->max($start),
                $occupancyEnd === null ? $end : $occupancyEnd->min($end),
            );
            $start = $start->addMonthsNoOverflow($months);
        }

        return $periods;
    }

    /**
     * Monthly rent in force on a date, after compounding escalations.
     */
    public function monthlyRentOn(Lease $lease, CarbonInterface $date): string
    {
        $rent = (string) $lease->rent_amount;

        if (! Money::isPositive((string) $lease->escalation_rate) || $lease->escalation_interval_months < 1) {
            return $rent;
        }

        $monthsElapsed = (int) CarbonImmutable::parse($lease->start_date)->startOfMonth()->diffInMonths(CarbonImmutable::parse($date)->startOfMonth());
        $escalations = intdiv(max(0, $monthsElapsed), $lease->escalation_interval_months);

        for ($i = 0; $i < $escalations; $i++) {
            $rent = Money::round(bcmul($rent, bcadd('1', bcdiv((string) $lease->escalation_rate, '100', 8), 8), 8));
        }

        return $rent;
    }

    public function rentFor(Lease $lease, BillingPeriod $period): string
    {
        return $this->chargeFor($this->monthlyRentOn($lease, $period->start), $lease, $period);
    }

    public function serviceChargeFor(Lease $lease, BillingPeriod $period): string
    {
        return $this->chargeFor((string) $lease->service_charge, $lease, $period);
    }

    private function chargeFor(string $monthlyAmount, Lease $lease, BillingPeriod $period): string
    {
        $fullPeriod = bcmul($monthlyAmount, (string) $lease->billing_frequency->months(), 2);

        if (! $period->isPartial()) {
            return $fullPeriod;
        }

        return Money::multiply($fullPeriod, bcdiv((string) $period->billedDays(), (string) $period->days(), 8));
    }
}
