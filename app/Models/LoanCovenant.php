<?php

namespace App\Models;

use App\Enums\CovenantMetric;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A financial covenant in the loan agreement, e.g. "DSCR of at least 1.25x".
 */
#[Fillable(['loan_id', 'metric', 'threshold', 'is_active'])]
class LoanCovenant extends Model
{
    use Auditable;

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'metric' => CovenantMetric::class,
            'threshold' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function tests(): HasMany
    {
        return $this->hasMany(CovenantTest::class)->orderByDesc('tested_on');
    }

    public function latestTest(): HasOne
    {
        return $this->hasOne(CovenantTest::class)->latestOfMany('tested_on');
    }

    /**
     * "DSCR at least 1.25x" or "LTV at most 65.0%".
     */
    public function requirement(): string
    {
        return $this->metric->shortLabel().' '.($this->metric->isMinimum() ? 'at least ' : 'at most ').$this->metric->format($this->threshold);
    }
}
