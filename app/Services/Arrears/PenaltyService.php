<?php

namespace App\Services\Arrears;

use App\Enums\ChargeType;
use App\Enums\RepaymentPlanStatus;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\PenaltyCharge;
use App\Models\PenaltyRule;
use App\Services\Billing\InvoiceService;
use App\Services\Ledger\InvalidJournalEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * Raises late-payment penalty invoices according to each lease's effective penalty rule.
 */
class PenaltyService
{
    public function __construct(
        private PenaltyCalculator $calculator,
        private InvoiceService $invoices,
    ) {}

    /**
     * Penalties that would be raised today, without raising them. Pass a rule to see
     * what that rule alone would charge on the leases it governs.
     *
     * @return Collection<int, PenaltyAssessment>
     */
    public function preview(CarbonInterface $on, ?PenaltyRule $onlyRule = null): Collection
    {
        return $this->candidates()
            ->map(function (Invoice $invoice) use ($on, $onlyRule): ?PenaltyAssessment {
                $rule = $this->ruleFor($invoice);

                if ($rule === null || ($onlyRule !== null && $rule->id !== $onlyRule->id) || $this->protectedByRepaymentPlan($invoice)) {
                    return null;
                }

                return $this->calculator->assess($invoice, $rule, $on);
            })
            ->filter()
            ->values();
    }

    public function assess(CarbonInterface $on): PenaltyRunResult
    {
        $result = new PenaltyRunResult;

        foreach ($this->preview($on) as $assessment) {
            try {
                $result->invoices[] = $this->charge($assessment, $on);
            } catch (InvalidJournalEntry $exception) {
                $result->failures[$assessment->invoice->number] = $exception->getMessage();
            }
        }

        return $result;
    }

    /**
     * @throws InvalidJournalEntry
     */
    private function charge(PenaltyAssessment $assessment, CarbonInterface $on): Invoice
    {
        $invoice = $assessment->invoice;

        return DB::transaction(function () use ($assessment, $invoice, $on): Invoice {
            $penaltyInvoice = $this->invoices->issue(
                $invoice->tenant,
                $on,
                $on,
                "Late payment penalty on {$invoice->number}",
                array_map(fn (array $occurrence): array => [
                    'charge_type' => ChargeType::Penalty,
                    'description' => "Penalty on {$invoice->number} (KES ".Number::format((float) $assessment->basis, 2)." overdue): {$assessment->rule->name}, assessed {$occurrence['date']->format('j M Y')}",
                    'amount' => $occurrence['amount'],
                    'vat' => false,
                ], $assessment->occurrences),
                $invoice->lease,
            );

            foreach ($assessment->occurrences as $occurrence) {
                PenaltyCharge::query()->create([
                    'invoice_id' => $invoice->id,
                    'penalty_invoice_id' => $penaltyInvoice->id,
                    'penalty_rule_id' => $assessment->rule->id,
                    'occurrence' => $occurrence['occurrence'],
                    'assessed_on' => $occurrence['date'],
                    'basis' => $assessment->basis,
                    'amount' => $occurrence['amount'],
                ]);
            }

            return $penaltyInvoice;
        });
    }

    /**
     * Overdue lease invoices, excluding penalty invoices themselves.
     *
     * @return Collection<int, Invoice>
     */
    private function candidates(): Collection
    {
        return Invoice::query()
            ->overdue()
            ->whereNotNull('lease_id')
            ->whereDoesntHave('lines', fn ($lines) => $lines->where('charge_type', ChargeType::Penalty))
            ->with(['lines', 'tenant', 'lease.penaltyRule', 'lease.unit.property.penaltyRule'])
            ->orderBy('due_date')
            ->get();
    }

    private function ruleFor(Invoice $invoice): ?PenaltyRule
    {
        /** @var Lease $lease */
        $lease = $invoice->lease;

        return $lease->effectivePenaltyRule()['rule'];
    }

    /**
     * Arrears covered by a repayment plan the tenant is keeping are not penalised.
     */
    private function protectedByRepaymentPlan(Invoice $invoice): bool
    {
        return $invoice->tenant->repaymentPlans()
            ->where('status', RepaymentPlanStatus::Active)
            ->whereDate('agreed_on', '>=', $invoice->issue_date)
            ->exists();
    }
}
