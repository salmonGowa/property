<?php

namespace App\Services\Debt;

use App\Enums\BillingFrequency;
use App\Enums\RepaymentType;
use App\Services\Money;
use Carbon\CarbonInterface;

/**
 * Works out a loan's repayment schedule. Interest on each instalment is charged on the
 * opening balance for the actual days since the previous instalment, over 365 days.
 * Equal instalments use the annuity formula at the periodic rate; the final instalment
 * clears whatever is left, including any balloon.
 */
class AmortisationSchedule
{
    private const SCALE = 14;

    /**
     * @param  list<CarbonInterface>  $dueDates
     * @return list<array{due_date: CarbonInterface, opening_balance: string, principal: string, interest: string, total: string, closing_balance: string}>
     */
    public function build(
        string $principal,
        string $annualRate,
        BillingFrequency $frequency,
        array $dueDates,
        CarbonInterface $interestFrom,
        RepaymentType $repaymentType,
        int $interestOnlyPeriods = 0,
        string $balloon = '0',
    ): array {
        $count = count($dueDates);

        if ($count === 0 || ! Money::isPositive($principal)) {
            return [];
        }

        $interestOnlyPeriods = min($interestOnlyPeriods, $count - 1);
        $amortisingPeriods = $count - $interestOnlyPeriods;
        $amortised = bcsub($principal, $balloon, 2);
        $regularPayment = $repaymentType === RepaymentType::Annuity ? $this->annuityPayment($principal, $balloon, $this->periodicRate($annualRate, $frequency), $amortisingPeriods) : '0';
        $equalPrincipal = $repaymentType === RepaymentType::EqualPrincipal ? bcdiv($amortised, (string) $amortisingPeriods, 2) : '0';

        $rows = [];
        $balance = Money::round($principal);
        $previous = $interestFrom;

        foreach (array_values($dueDates) as $index => $dueDate) {
            $days = max(0, (int) $previous->diffInDays($dueDate));
            $interest = $this->interestFor($balance, $annualRate, $days);
            $isLast = $index === $count - 1;

            $principalPart = match (true) {
                $isLast => $balance,
                $index < $interestOnlyPeriods, $repaymentType === RepaymentType::InterestOnly => '0.00',
                $repaymentType === RepaymentType::Annuity => $this->clamp(bcsub($regularPayment, $interest, 2), $balance),
                default => $this->clamp($equalPrincipal, $balance),
            };

            $rows[] = [
                'due_date' => $dueDate,
                'opening_balance' => $balance,
                'principal' => $principalPart,
                'interest' => $interest,
                'total' => bcadd($principalPart, $interest, 2),
                'closing_balance' => bcsub($balance, $principalPart, 2),
            ];

            $balance = bcsub($balance, $principalPart, 2);
            $previous = $dueDate;
        }

        return $rows;
    }

    /**
     * Interest on a balance for a number of days, actual/365.
     */
    public function interestFor(string $balance, string $annualRate, int $days): string
    {
        return Money::round(bcdiv(bcmul(bcmul($balance, $annualRate, self::SCALE), (string) $days, self::SCALE), '36500', self::SCALE));
    }

    /**
     * Level payment that repays (principal - balloon) over the periods, leaving the balloon at the end.
     */
    private function annuityPayment(string $principal, string $balloon, string $rate, int $periods): string
    {
        if (bccomp($rate, '0', self::SCALE) === 0) {
            return bcdiv(bcsub($principal, $balloon, 2), (string) $periods, 2);
        }

        $growth = bcpow(bcadd('1', $rate, self::SCALE), (string) $periods, self::SCALE);
        $presentBalloon = bcdiv($balloon, $growth, self::SCALE);
        $annuityFactor = bcdiv($rate, bcsub('1', bcdiv('1', $growth, self::SCALE), self::SCALE), self::SCALE);

        return Money::round(bcmul(bcsub($principal, $presentBalloon, self::SCALE), $annuityFactor, self::SCALE));
    }

    private function periodicRate(string $annualRate, BillingFrequency $frequency): string
    {
        return bcdiv(bcmul($annualRate, (string) $frequency->months(), self::SCALE), '1200', self::SCALE);
    }

    private function clamp(string $amount, string $balance): string
    {
        return match (true) {
            bccomp($amount, '0', 2) < 0 => '0.00',
            bccomp($amount, $balance, 2) > 0 => $balance,
            default => bcadd($amount, '0', 2),
        };
    }
}
