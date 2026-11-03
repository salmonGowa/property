<?php

namespace App\Services\Procurement;

use App\Enums\AccountType;
use App\Enums\InvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\Account;
use App\Models\Property;
use App\Models\PurchaseOrder;
use App\Models\SupplierBill;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\Ledger\InvalidJournalEntry;
use App\Services\Ledger\JournalLineData;
use App\Services\Ledger\LedgerAccounts;
use App\Services\Ledger\PostingService;
use App\Services\Money;
use App\Services\Numbering\DocumentNumber;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Enters supplier invoices into Accounts Payable: debit the expense (or asset) accounts
 * and recoverable VAT input, credit what the supplier is owed, and credit withholding
 * tax payable to KRA for the share the company must withhold.
 */
class SupplierBillService
{
    public function __construct(
        private PostingService $posting,
        private LedgerAccounts $accounts,
        private DocumentNumber $numbers,
        private PurchaseOrderService $purchaseOrders,
    ) {}

    /**
     * Account types a purchase may be charged to: expenses, or assets such as stores and equipment.
     *
     * @return list<AccountType>
     */
    public static function chargeableAccountTypes(): array
    {
        return [AccountType::Expense, AccountType::Asset];
    }

    /**
     * @param  list<array{account_id: int|string, description: string, amount: int|string, vat?: bool}>  $lines
     *
     * @throws InvalidJournalEntry
     * @throws ProcurementActionRefused
     */
    public function record(
        Vendor $vendor,
        string $vendorInvoiceNumber,
        CarbonInterface|string $billDate,
        CarbonInterface|string|null $dueDate,
        string $description,
        array $lines,
        ?PurchaseOrder $purchaseOrder = null,
        ?WorkOrder $workOrder = null,
        ?Property $property = null,
    ): SupplierBill {
        $billDate = Carbon::parse($billDate);
        $dueDate = $dueDate ? Carbon::parse($dueDate) : $billDate->copy()->addDays($vendor->payment_terms_days);
        $vendorInvoiceNumber = strtoupper(trim($vendorInvoiceNumber));

        if ($dueDate->lt($billDate)) {
            throw new ProcurementActionRefused('The due date cannot be before the bill date.');
        }

        if ($this->alreadyEntered($vendor, $vendorInvoiceNumber)) {
            throw new ProcurementActionRefused("{$vendor->name}'s invoice {$vendorInvoiceNumber} has already been entered.");
        }

        $prepared = $this->prepareLines($vendor, $lines);
        $subtotal = Money::sum(array_column($prepared, 'amount'));
        $vat = Money::sum(array_column($prepared, 'vat_amount'));
        $total = bcadd($subtotal, $vat, 2);

        if ($purchaseOrder !== null) {
            $this->assertBillableAgainst($purchaseOrder, $vendor, $subtotal);
            $workOrder ??= $purchaseOrder->workOrder;
        }

        $propertyId = $workOrder?->property_id ?? $purchaseOrder?->property_id ?? $property?->id;
        $whtRate = $vendor->withholding_tax_type->rate();
        $wht = Money::percentOf($subtotal, $whtRate);
        $payable = bcsub($total, $wht, 2);

        $bill = DB::transaction(function () use ($vendor, $vendorInvoiceNumber, $billDate, $dueDate, $description, $prepared, $purchaseOrder, $workOrder, $propertyId, $subtotal, $vat, $total, $whtRate, $wht, $payable): SupplierBill {
            $bill = SupplierBill::query()->create([
                'number' => $this->numbers->next(DocumentNumber::SUPPLIER_BILL),
                'vendor_id' => $vendor->id,
                'vendor_invoice_number' => $vendorInvoiceNumber,
                'purchase_order_id' => $purchaseOrder?->id,
                'work_order_id' => $workOrder?->id,
                'property_id' => $propertyId,
                'description' => $description,
                'bill_date' => $billDate,
                'due_date' => $dueDate,
                'subtotal' => $subtotal,
                'vat_amount' => $vat,
                'total' => $total,
                'withholding_tax_rate' => $whtRate,
                'withholding_tax_amount' => $wht,
                'payable_amount' => $payable,
                'created_by' => Auth::id(),
            ]);
            $bill->lines()->createMany($prepared);

            $journalLines = array_map(fn (array $line): JournalLineData => JournalLineData::debit($line['account_id'], $line['amount'], $line['description']), $prepared);

            if (Money::isPositive($vat)) {
                $journalLines[] = JournalLineData::debit($this->accounts->get('vat_input'), $vat, "VAT input {$vendorInvoiceNumber}");
            }

            $journalLines[] = JournalLineData::credit($this->accounts->get('payable'), $payable, $vendor->name);

            if (Money::isPositive($wht)) {
                $journalLines[] = JournalLineData::credit($this->accounts->get('withholding_tax_payable'), $wht, "WHT {$whtRate}% on {$vendor->name}");
            }

            $entry = $this->posting->post($billDate, "{$bill->number} {$vendor->name} inv {$vendorInvoiceNumber}: {$description}", $journalLines, $vendorInvoiceNumber, $bill);
            $bill->update(['journal_entry_id' => $entry->id]);

            return $bill;
        });

        if ($purchaseOrder !== null) {
            $this->purchaseOrders->refreshBillingStatus($purchaseOrder);
        }

        return $bill->refresh();
    }

    /**
     * Cancel a bill entered in error by reversing its posting. Paid bills need their payments reversed first.
     *
     * @throws InvalidJournalEntry
     */
    public function void(SupplierBill $bill, string $reason, ?User $user, CarbonInterface|string|null $date = null): void
    {
        if ($bill->status === InvoiceStatus::Void) {
            throw new InvalidJournalEntry("{$bill->number} is already void.");
        }

        if ($bill->allocations()->exists()) {
            throw new InvalidJournalEntry("{$bill->number} has payments applied. Reverse those before voiding it.");
        }

        DB::transaction(function () use ($bill, $reason, $user, $date): void {
            $reversal = $this->posting->reverse($bill->journalEntry, $date ?? today(), "Void {$bill->number}: {$reason}");

            $bill->update([
                'status' => InvoiceStatus::Void,
                'void_journal_entry_id' => $reversal->id,
                'voided_at' => now(),
                'voided_by' => $user?->id,
                'void_reason' => $reason,
            ]);
        });

        if ($bill->purchaseOrder !== null) {
            $this->purchaseOrders->refreshBillingStatus($bill->purchaseOrder);
        }
    }

    public function refreshStatus(SupplierBill $bill): void
    {
        if ($bill->status === InvoiceStatus::Void) {
            return;
        }

        $paid = Money::round((string) $bill->allocations()->sum('amount'));

        $bill->update([
            'amount_paid' => $paid,
            'status' => match (true) {
                bccomp($paid, (string) $bill->payable_amount, 2) >= 0 => InvoiceStatus::Paid,
                Money::isPositive($paid) => InvoiceStatus::PartiallyPaid,
                default => InvoiceStatus::Unpaid,
            },
        ]);
    }

    /**
     * @param  list<array{account_id: int|string, description: string, amount: int|string, vat?: bool}>  $lines
     * @return list<array{account_id: int, description: string, amount: string, vat_rate: string, vat_amount: string}>
     */
    private function prepareLines(Vendor $vendor, array $lines): array
    {
        $lines = array_values(array_filter($lines, fn (array $line): bool => Money::isPositive(Money::round((string) $line['amount']))));

        if ($lines === []) {
            throw new ProcurementActionRefused('A bill needs at least one line greater than zero.');
        }

        $vatRate = (string) config('ledger.vat_rate');
        $chargeable = Account::query()->postable()->whereIn('type', self::chargeableAccountTypes())->pluck('id')->all();

        return array_map(function (array $line) use ($vendor, $vatRate, $chargeable): array {
            $line['vat'] = (bool) ($line['vat'] ?? false);

            if ($line['vat'] && ! $vendor->is_vat_registered) {
                throw new ProcurementActionRefused("{$vendor->name} is not VAT registered, so VAT input cannot be claimed.");
            }

            if (! in_array((int) $line['account_id'], $chargeable, true)) {
                throw new ProcurementActionRefused('Bills must be charged to an active expense or asset account.');
            }

            $amount = Money::round((string) $line['amount']);

            return [
                'account_id' => (int) $line['account_id'],
                'description' => $line['description'],
                'amount' => $amount,
                'vat_rate' => $line['vat'] ? $vatRate : '0.00',
                'vat_amount' => $line['vat'] ? Money::percentOf($amount, $vatRate) : '0.00',
            ];
        }, $lines);
    }

    private function assertBillableAgainst(PurchaseOrder $order, Vendor $vendor, string $subtotal): void
    {
        if ($order->vendor_id !== $vendor->id) {
            throw new ProcurementActionRefused("{$order->number} was raised to {$order->vendor->name}, not {$vendor->name}.");
        }

        if ($order->status !== PurchaseOrderStatus::Approved) {
            throw new ProcurementActionRefused("{$order->number} is {$order->status->getLabel()}. Only approved orders can be billed.");
        }

        $remaining = $order->remainingSubtotal();

        if (bccomp($subtotal, $remaining, 2) > 0) {
            throw new ProcurementActionRefused("The bill (KES {$subtotal} before VAT) is more than the KES {$remaining} left on {$order->number}. Ask for a revised order to be approved.");
        }
    }

    private function alreadyEntered(Vendor $vendor, string $vendorInvoiceNumber): bool
    {
        return $vendor->bills()
            ->where('vendor_invoice_number', $vendorInvoiceNumber)
            ->where('status', '!=', InvoiceStatus::Void)
            ->exists();
    }
}
