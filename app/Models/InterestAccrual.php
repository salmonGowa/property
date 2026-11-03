<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Month-end interest accrued on a loan, reversed on the first day of the next month.
 */
#[Fillable(['loan_id', 'period_end', 'days', 'amount', 'journal_entry_id', 'reversal_journal_entry_id'])]
class InterestAccrual extends Model
{
    protected function casts(): array
    {
        return [
            'period_end' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
