<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\WorkOrderCategory;
use App\Enums\WorkOrderPriority;
use App\Enums\WorkOrderStatus;
use App\Models\Concerns\Auditable;
use Database\Factories\WorkOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A repair or maintenance job on a property or unit, from report to completion.
 */
#[Fillable(['number', 'property_id', 'unit_id', 'tenant_id', 'maintenance_schedule_id', 'title', 'description', 'category', 'priority', 'status', 'reported_at', 'due_on', 'vendor_id', 'estimated_cost', 'assigned_at', 'started_at', 'completed_at', 'resolution', 'tenant_chargeable', 'takes_unit_offline', 'recharge_invoice_id', 'cancel_reason', 'created_by'])]
class WorkOrder extends Model
{
    /** @use HasFactory<WorkOrderFactory> */
    use Auditable, HasFactory;

    protected function casts(): array
    {
        return [
            'category' => WorkOrderCategory::class,
            'priority' => WorkOrderPriority::class,
            'status' => WorkOrderStatus::class,
            'reported_at' => 'datetime',
            'due_on' => 'date',
            'assigned_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'estimated_cost' => 'decimal:2',
            'tenant_chargeable' => 'boolean',
            'takes_unit_offline' => 'boolean',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(MaintenanceSchedule::class, 'maintenance_schedule_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(SupplierBill::class);
    }

    public function rechargeInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'recharge_invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    #[Scope]
    protected function unfinished(Builder $query): Builder
    {
        return $query->whereIn('status', WorkOrderStatus::unfinished());
    }

    #[Scope]
    protected function overdue(Builder $query): Builder
    {
        return $query->unfinished()->whereDate('due_on', '<', today());
    }

    public function isOverdue(): bool
    {
        return $this->status->isUnfinished() && $this->due_on->lt(today());
    }

    /**
     * What the work has cost so far: supplier bills charged to it, before VAT.
     */
    public function actualCost(): string
    {
        return bcadd((string) $this->bills()->where('status', '!=', InvoiceStatus::Void)->sum('subtotal'), '0', 2);
    }
}
