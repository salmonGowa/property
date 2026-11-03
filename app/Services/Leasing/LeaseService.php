<?php

namespace App\Services\Leasing;

use App\Enums\CreditNoteType;
use App\Enums\InvoiceStatus;
use App\Enums\LeaseStatus;
use App\Enums\UnitStatus;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Lease;
use App\Services\Billing\BillingService;
use App\Services\Billing\CreditNoteService;
use App\Services\Billing\InvoiceService;
use App\Services\Ledger\InvalidJournalEntry;
use App\Services\Money;
use App\Services\Numbering\DocumentNumber;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LeaseService
{
    public function __construct(
        private DocumentNumber $numbers,
        private BillingService $billing,
        private InvoiceService $invoices,
        private CreditNoteService $creditNotes,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createDraft(array $attributes): Lease
    {
        return DB::transaction(fn (): Lease => Lease::query()->create([
            ...$attributes,
            'reference' => $this->numbers->next(DocumentNumber::LEASE),
            'status' => LeaseStatus::Draft,
            'created_by' => Auth::id(),
        ]));
    }

    /**
     * Put a draft lease into force: occupy the unit, invoice the deposit and bill
     * any periods already due. A renewal ends the lease it replaces the day before.
     *
     * @throws LeaseActionRefused
     * @throws InvalidJournalEntry
     */
    public function activate(Lease $lease, ?CarbonInterface $billUpTo = null): void
    {
        if ($lease->status !== LeaseStatus::Draft) {
            throw new LeaseActionRefused("Lease {$lease->reference} is {$lease->status->getLabel()}; only drafts can be activated.");
        }

        $unit = $lease->unit;
        $occupying = $unit->activeLease()->first();

        if ($occupying !== null && $occupying->id !== $lease->renewed_from_id) {
            throw new LeaseActionRefused("Unit {$unit->fullCode()} is already let under lease {$occupying->reference}.");
        }

        if ($unit->status === UnitStatus::Maintenance) {
            throw new LeaseActionRefused("Unit {$unit->fullCode()} is under maintenance.");
        }

        DB::transaction(function () use ($lease, $unit, $occupying, $billUpTo): void {
            if ($occupying !== null) {
                $occupying->update([
                    'status' => LeaseStatus::Terminated,
                    'terminated_on' => Carbon::parse($lease->start_date)->subDay(),
                    'termination_reason' => "Renewed as {$lease->reference}",
                ]);
            }

            $lease->update(['status' => LeaseStatus::Active, 'activated_at' => now()]);
            $unit->update(['status' => UnitStatus::Occupied]);

            if ($occupying === null) {
                $this->billing->billDeposit($lease, today()->max($lease->start_date));
            }

            $this->billing->billLease($lease, $billUpTo ?? today());
        });
    }

    /**
     * End the lease: free the unit, and credit (or void, if unpaid) rent already billed
     * for days after the termination date.
     *
     * @return list<CreditNote|Invoice> Credit notes issued and invoices voided
     *
     * @throws LeaseActionRefused
     * @throws InvalidJournalEntry
     */
    public function terminate(Lease $lease, CarbonInterface|string $terminatedOn, string $reason): array
    {
        if ($lease->status !== LeaseStatus::Active) {
            throw new LeaseActionRefused("Only active leases can be terminated; {$lease->reference} is {$lease->status->getLabel()}.");
        }

        $terminatedOn = Carbon::parse($terminatedOn);

        if ($terminatedOn->lt($lease->start_date)) {
            throw new LeaseActionRefused('The termination date cannot be before the lease started.');
        }

        return DB::transaction(function () use ($lease, $terminatedOn, $reason): array {
            $lease->update([
                'status' => LeaseStatus::Terminated,
                'terminated_on' => $terminatedOn,
                'termination_reason' => $reason,
            ]);

            $lease->unit->update(['status' => UnitStatus::Vacant]);

            return $this->creditUnusedPeriods($lease, $terminatedOn);
        });
    }

    /**
     * @return list<CreditNote|Invoice>
     *
     * @throws InvalidJournalEntry
     */
    private function creditUnusedPeriods(Lease $lease, CarbonInterface $terminatedOn): array
    {
        $adjusted = [];

        $billedBeyond = $lease->invoices()
            ->whereNotNull('period_start')
            ->where('status', '!=', InvoiceStatus::Void)
            ->whereDate('period_end', '>', $terminatedOn)
            ->get();

        foreach ($billedBeyond as $invoice) {
            if ($invoice->period_start->gt($terminatedOn) && ! $invoice->allocations()->exists()) {
                $this->invoices->void($invoice, "Lease {$lease->reference} terminated on {$terminatedOn->toDateString()}", Auth::user());
                $adjusted[] = $invoice;

                continue;
            }

            $billedDays = (int) $invoice->period_start->diffInDays($invoice->period_end) + 1;
            $unusedDays = (int) $terminatedOn->copy()->max($invoice->period_start->copy()->subDay())->diffInDays($invoice->period_end);
            $credit = Money::multiply((string) $invoice->total, bcdiv((string) $unusedDays, (string) $billedDays, 10));
            $credit = Money::min($credit, $this->creditNotes->creditableAmount($invoice));

            if (Money::isPositive($credit)) {
                $adjusted[] = $this->creditNotes->issue($invoice, CreditNoteType::Termination, $credit, "{$unusedDays} unused days after termination on {$terminatedOn->format('j M Y')}");
            }
        }

        return $adjusted;
    }

    /**
     * Draft a follow-on lease for the same tenant and unit, carrying the terms over.
     *
     * @throws LeaseActionRefused
     */
    public function renew(Lease $lease, CarbonInterface|string $startDate, CarbonInterface|string|null $endDate, string $rentAmount): Lease
    {
        if ($lease->renewal()->exists()) {
            throw new LeaseActionRefused("Lease {$lease->reference} has already been renewed as {$lease->renewal->reference}.");
        }

        return $this->createDraft([
            ...$lease->only(['tenant_id', 'unit_id', 'billing_frequency', 'service_charge', 'charge_vat', 'due_day', 'escalation_rate', 'escalation_interval_months', 'notes']),
            'start_date' => Carbon::parse($startDate),
            'end_date' => $endDate ? Carbon::parse($endDate) : null,
            'rent_amount' => $rentAmount,
            'deposit_amount' => '0.00',
            'renewed_from_id' => $lease->id,
        ]);
    }
}
