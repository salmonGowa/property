<?php

namespace App\Services\Arrears;

use App\Enums\PenaltyCapType;
use App\Enums\PenaltyMethod;
use App\Enums\PenaltyRecurrence;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\PenaltyRule;
use App\Services\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Works out, without writing anything, which penalties an overdue invoice has incurred.
 *
 * Assessments start the day after the grace period ends and repeat on the rule's
 * schedule. Each is charged once; assessments dated before the rule's effective date
 * are never charged, so editing a rule never back-charges tenants.
 */
class PenaltyCalculator
{
    public function assess(Invoice $invoice, PenaltyRule $rule, CarbonInterface $on): ?PenaltyAssessment
    {
        $on = CarbonImmutable::parse($on)->startOfDay();
        $basis = $this->basis($invoice, $rule);

        if (! Money::isPositive($basis) || bccomp($basis, (string) $rule->minimum_overdue, 2) < 0) {
            return null;
        }

        $alreadyCharged = $invoice->penaltyCharges()->where('penalty_rule_id', $rule->id);
        $chargedOccurrences = (clone $alreadyCharged)->pluck('occurrence')->all();
        $capRemaining = $this->capRemaining($invoice, $rule, (string) (clone $alreadyCharged)->sum('amount'));

        $occurrences = [];
        $previousDate = CarbonImmutable::parse($invoice->due_date)->startOfDay();

        foreach ($this->schedule($invoice, $rule, $on) as $number => $date) {
            $days = (int) $previousDate->diffInDays($date);
            $previousDate = $date;

            if (in_array($number, $chargedOccurrences, true) || $date->lt(CarbonImmutable::parse($rule->effective_from))) {
                continue;
            }

            $amount = $this->amountFor($rule, $basis, $days);

            if ($capRemaining !== null) {
                $amount = Money::min($amount, $capRemaining);
                $capRemaining = bcsub($capRemaining, $amount, 2);
            }

            if (Money::isPositive($amount)) {
                $occurrences[] = ['occurrence' => $number, 'date' => $date, 'amount' => $amount, 'days' => $days];
            }
        }

        return $occurrences === [] ? null : new PenaltyAssessment($invoice, $rule, $basis, $occurrences);
    }

    /**
     * The unpaid part of the invoice that the rule covers, e.g. rent and service charge but not utilities.
     */
    public function basis(Invoice $invoice, PenaltyRule $rule): string
    {
        $total = (string) $invoice->total;

        if (! Money::isPositive($total)) {
            return '0.00';
        }

        $covered = Money::sum($invoice->lines
            ->filter(fn (InvoiceLine $line): bool => $rule->appliesToChargeType($line->charge_type))
            ->map(fn (InvoiceLine $line): string => $line->total()));

        return Money::multiply($invoice->balanceDue(), bcdiv($covered, $total, 10));
    }

    /**
     * Assessment dates up to the given day, keyed by occurrence number.
     *
     * @return array<int, CarbonImmutable>
     */
    private function schedule(Invoice $invoice, PenaltyRule $rule, CarbonImmutable $on): array
    {
        $first = CarbonImmutable::parse($invoice->due_date)->startOfDay()->addDays($rule->grace_days + 1);
        $dates = [];

        for ($number = 1; $number <= 120; $number++) {
            $date = match ($rule->recurrence) {
                PenaltyRecurrence::Once => $first,
                PenaltyRecurrence::Monthly => $first->addMonthsNoOverflow($number - 1),
                PenaltyRecurrence::EveryNDays => $first->addDays(($number - 1) * max(1, (int) $rule->recurrence_days)),
            };

            if ($date->gt($on)) {
                break;
            }

            $dates[$number] = $date;

            if ($rule->recurrence === PenaltyRecurrence::Once) {
                break;
            }
        }

        return $dates;
    }

    private function amountFor(PenaltyRule $rule, string $basis, int $days): string
    {
        return match ($rule->method) {
            PenaltyMethod::Percentage => Money::percentOf($basis, (string) $rule->rate),
            PenaltyMethod::Flat => Money::round((string) $rule->rate),
            PenaltyMethod::DailyPercentage => Money::percentOf($basis, bcmul((string) $rule->rate, (string) $days, 8)),
        };
    }

    private function capRemaining(Invoice $invoice, PenaltyRule $rule, string $alreadyCharged): ?string
    {
        $cap = match ($rule->cap_type) {
            PenaltyCapType::None => null,
            PenaltyCapType::Amount => (string) $rule->cap_value,
            PenaltyCapType::PercentOfInvoice => Money::percentOf((string) $invoice->total, (string) $rule->cap_value),
        };

        if ($cap === null) {
            return null;
        }

        $remaining = bcsub($cap, $alreadyCharged, 2);

        return bccomp($remaining, '0', 2) > 0 ? $remaining : '0.00';
    }
}
