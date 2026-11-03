<?php

namespace App\Models;

use App\Enums\LeaseStatus;
use App\Enums\UnitStatus;
use App\Enums\UnitType;
use App\Models\Concerns\Auditable;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['property_id', 'code', 'type', 'status', 'floor', 'bedrooms', 'bathrooms', 'area_sqft', 'market_rent', 'service_charge', 'notes'])]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use Auditable, HasFactory;

    protected $attributes = [
        'status' => 'vacant',
    ];

    protected function casts(): array
    {
        return [
            'type' => UnitType::class,
            'status' => UnitStatus::class,
            'area_sqft' => 'decimal:2',
            'market_rent' => 'decimal:2',
            'service_charge' => 'decimal:2',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    public function activeLease(): HasOne
    {
        return $this->hasOne(Lease::class)->where('status', LeaseStatus::Active);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    /**
     * Property code and unit code together, e.g. "KIL-01 / A-101".
     */
    public function fullCode(): string
    {
        return "{$this->property->code} / {$this->code}";
    }
}
