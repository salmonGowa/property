<?php

namespace App\Models;

use App\Enums\KenyaCounty;
use App\Enums\PropertyType;
use App\Enums\UnitStatus;
use App\Models\Concerns\Auditable;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['code', 'name', 'type', 'county', 'town', 'address', 'lr_number', 'total_area_sqft', 'year_built', 'manager_id', 'penalty_rule_id', 'photo_path', 'description', 'is_active'])]
class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use Auditable, HasFactory;

    protected function casts(): array
    {
        return [
            'type' => PropertyType::class,
            'county' => KenyaCounty::class,
            'total_area_sqft' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function penaltyRule(): BelongsTo
    {
        return $this->belongsTo(PenaltyRule::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function leases(): HasManyThrough
    {
        return $this->hasManyThrough(Lease::class, Unit::class);
    }

    public function valuations(): HasMany
    {
        return $this->hasMany(PropertyValuation::class)->orderByDesc('valued_on');
    }

    public function latestValuation(): HasOne
    {
        return $this->hasOne(PropertyValuation::class)->latestOfMany('valued_on');
    }

    public function loans(): BelongsToMany
    {
        return $this->belongsToMany(Loan::class)->withTimestamps();
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    /**
     * Share of units currently occupied, as a whole percentage.
     */
    public function occupancyRate(): int
    {
        $total = $this->units_count ?? $this->units()->count();

        if ($total === 0) {
            return 0;
        }

        $occupied = $this->occupied_units_count ?? $this->units()->where('status', UnitStatus::Occupied)->count();

        return (int) round($occupied / $total * 100);
    }
}
