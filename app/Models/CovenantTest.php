<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A covenant measured on a date, with the figures it was worked out from.
 */
#[Fillable(['loan_covenant_id', 'tested_on', 'actual', 'threshold', 'passed', 'inputs'])]
class CovenantTest extends Model
{
    protected function casts(): array
    {
        return [
            'tested_on' => 'date',
            'actual' => 'decimal:3',
            'threshold' => 'decimal:3',
            'passed' => 'boolean',
            'inputs' => 'array',
        ];
    }

    public function covenant(): BelongsTo
    {
        return $this->belongsTo(LoanCovenant::class, 'loan_covenant_id');
    }
}
