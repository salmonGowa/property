<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supplier's invoice, posted to Accounts Payable. Never edited; voided instead.
 */
#[Fillable(['number', 'vendor_id', 'vendor_invoice_number', 'purchase_order_id', 'work_order_id', 'property_id', 'description', 'bill_date', 'due_date', 'status', 'subtotal', 'vat_amount', 'total', 'withholding_tax_rate', 'withholding_tax_amount', 'payable_amount', 'amount_paid', 'journal_entry_id', 'void_journal_entry_id', 'voided_at', 'voided_by', 'void_reason', 'created_by'])]
class SupplierBill extends Model
{
    use Auditable;

    protected $attributes = [
        'status' => 'unpaid',
        'amount_paid' => '0.00',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'bill_date' => 'date',
            'due_date' => 'date',
            'voided_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'withholding_tax_rate' => 'decimal:2',
            'withholding_tax_amount' => 'decimal:2',
            'payable_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierBillLine::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    #[Scope]
    protected function outstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::PartiallyPaid]);
    }

    #[Scope]
    protected function overdue(Builder $query): Builder
    {
        return $query->outstanding()->whereDate('due_date', '<', today());
    }

    public function balanceDue(): string
    {
        return $this->status === InvoiceStatus::Void ? '0.00' : bcsub((string) $this->payable_amount, (string) $this->amount_paid, 2);
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, [InvoiceStatus::Unpaid, InvoiceStatus::PartiallyPaid], true)
            && $this->due_date->lt(today());
    }
}
