<?php

namespace App\Models;

use App\Enums\BillingFrequency;
use App\Enums\WorkOrderCategory;
use App\Enums\WorkOrderPriority;
use App\Models\Concerns\Auditable;
use Database\Factories\MaintenanceScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Recurring preventive maintenance (lift servicing, fumigation, tank cleaning) that raises work orders when due.
 */
#[Fillable(['property_id', 'unit_id', 'title', 'description', 'category', 'priority', 'vendor_id', 'frequency', 'next_due_on', 'lead_days', 'last_raised_on', 'is_active'])]
class MaintenanceSchedule extends Model
{
    /** @use HasFactory<MaintenanceScheduleFactory> */
    use Auditable, HasFactory;

    protected $attributes = [
        'priority' => 'normal',
        'lead_days' => 7,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'category' => WorkOrderCategory::class,
            'priority' => WorkOrderPriority::class,
            'frequency' => BillingFrequency::class,
            'next_due_on' => 'date',
            'last_raised_on' => 'date',
            'is_active' => 'boolean',
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

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }
}
