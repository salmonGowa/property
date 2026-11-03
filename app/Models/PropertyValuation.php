<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\PropertyValuationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An open-market valuation of a property, used for loan-to-value.
 */
#[Fillable(['property_id', 'valued_on', 'market_value', 'valuer', 'notes', 'created_by'])]
class PropertyValuation extends Model
{
    /** @use HasFactory<PropertyValuationFactory> */
    use Auditable, HasFactory;

    protected function casts(): array
    {
        return [
            'valued_on' => 'date',
            'market_value' => 'decimal:2',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
