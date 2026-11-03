<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['loan_id', 'effective_from', 'base_rate', 'margin', 'previous_rate', 'interest_rate', 'reason', 'created_by'])]
class LoanRateChange extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'base_rate' => 'decimal:3',
            'margin' => 'decimal:3',
            'previous_rate' => 'decimal:3',
            'interest_rate' => 'decimal:3',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
