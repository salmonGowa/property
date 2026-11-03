<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An order to a supplier that must be approved, within the approver's limit, before the supplier can bill against it.
 */
#[Fillable(['number', 'vendor_id', 'property_id', 'work_order_id', 'description', 'order_date', 'expected_date', 'status', 'subtotal', 'vat_amount', 'total', 'notes', 'requested_by', 'submitted_at', 'approved_by', 'approved_at', 'rejected_by', 'rejection_reason', 'closed_at', 'close_reason'])]
class PurchaseOrder extends Model
{
    use Auditable;

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'order_date' => 'date',
            'expected_date' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'closed_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(SupplierBill::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Amount (before VAT) already billed by the supplier against this order.
     */
    public function billedSubtotal(): string
    {
        return bcadd((string) $this->bills()->where('status', '!=', InvoiceStatus::Void)->sum('subtotal'), '0', 2);
    }

    /**
     * Amount (before VAT) the supplier can still bill.
     */
    public function remainingSubtotal(): string
    {
        if (in_array($this->status, [PurchaseOrderStatus::Closed, PurchaseOrderStatus::Cancelled], true)) {
            return '0.00';
        }

        $remaining = bcsub((string) $this->subtotal, $this->billedSubtotal(), 2);

        return bccomp($remaining, '0', 2) > 0 ? $remaining : '0.00';
    }
}
