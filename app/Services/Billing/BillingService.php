<?php

namespace App\Services\Billing;

use App\Enums\ChargeType;
use App\Models\Invoice;
use App\Models\Lease;
use App\Services\Leasing\BillingPeriod;
use App\Services\Leasing\RentSchedule;
use App\Services\Ledger\InvalidJournalEntry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Generates rent and service charge invoices for leases. Safe to run repeatedly:
 * each lease period is billed at most once.
 */
class BillingService
{
    public function __construct(
        private RentSchedule $schedule,
        private InvoiceService $invoices,
    ) {}

    /**
     * Bill every active lease for all unbilled periods starting on or before the date.
     */
    public function run(CarbonInterface $upTo): BillingRunResult
    {
        $result = new BillingRunResult;

        Lease::query()->active()->with(['tenant', 'unit.property'])->orderBy('id')->each(function (Lease $lease) use ($upTo, $result): void {
            try {
                $result->invoices = [...$result->invoices, ...$this->billLease($lease, $upTo)];
            } catch (InvalidJournalEntry $exception) {
                $result->failures[$lease->reference] = $exception->getMessage();
            }
        });

        return $result;
    }

    /**
     * @return list<Invoice>
     *
     * @throws InvalidJournalEntry
     */
    public function billLease(Lease $lease, CarbonInterface $upTo): array
    {
        $issued = [];

        foreach ($this->schedule->periodsUpTo($lease, $upTo) as $period) {
            $billingKey = "lease:{$lease->id}:{$period->start->toDateString()}";

            if (Invoice::query()->where('billing_key', $billingKey)->exists()) {
                continue;
            }

            $issued[] = $this->invoices->issue(
                $lease->tenant,
                $period->billedFrom,
                $this->dueDate($lease, $period),
                "{$lease->unit->fullCode()} - {$period->label()}",
                [
                    [
                        'charge_type' => ChargeType::Rent,
                        'description' => "Rent {$period->label()}".($period->isPartial() ? " (prorated {$period->billedDays()}/{$period->days()} days)" : ''),
                        'amount' => $this->schedule->rentFor($lease, $period),
                        'vat' => $lease->charge_vat,
                    ],
                    [
                        'charge_type' => ChargeType::ServiceCharge,
                        'description' => "Service charge {$period->label()}",
                        'amount' => $this->schedule->serviceChargeFor($lease, $period),
                        'vat' => $lease->charge_vat,
                    ],
                ],
                $lease,
                $period->billedFrom,
                $period->billedTo,
                $billingKey,
            );
        }

        return $issued;
    }

    /**
     * Invoice the security deposit held against the lease (no VAT; credited to deposits held).
     *
     * @throws InvalidJournalEntry
     */
    public function billDeposit(Lease $lease, CarbonInterface $issueDate): ?Invoice
    {
        if (bccomp((string) $lease->deposit_amount, '0', 2) !== 1) {
            return null;
        }

        return $this->invoices->issue(
            $lease->tenant,
            $issueDate,
            $issueDate,
            "{$lease->unit->fullCode()} - Security deposit",
            [[
                'charge_type' => ChargeType::Deposit,
                'description' => "Refundable security deposit, lease {$lease->reference}",
                'amount' => (string) $lease->deposit_amount,
                'vat' => false,
            ]],
            $lease,
            billingKey: "lease:{$lease->id}:deposit",
        );
    }

    /**
     * The lease's due day in the period's first month, never before the period is billed from.
     */
    private function dueDate(Lease $lease, BillingPeriod $period): CarbonImmutable
    {
        $due = $period->start->setDay(min($lease->due_day, $period->start->daysInMonth));

        return $due->max($period->billedFrom);
    }
}
