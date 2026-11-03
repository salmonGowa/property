<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a loan's repayment schedule. Unpaid rows are rebuilt whenever the
 * balance, rate or terms change; paid rows are kept as history.
 */
#[Fillable(['loan_id', 'sequence', 'due_date', 'opening_balance', 'principal', 'interest', 'total', 'closing_balance', 'interest_rate', 'loan_repayment_id'])]
class LoanInstalment extends Model
{
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'opening_balance' => 'decimal:2',
            'principal' => 'decimal:2',
            'interest' => 'decimal:2',
            'total' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'interest_rate' => 'decimal:3',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function repayment(): BelongsTo
    {
        return $this->belongsTo(LoanRepayment::class, 'loan_repayment_id');
    }

    public function isPaid(): bool
    {
        return $this->loan_repayment_id !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isPaid() && $this->due_date->lt(today());
    }
}
