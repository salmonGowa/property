<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Money paid to a lender: a scheduled instalment or a principal prepayment.
 */
#[Fillable(['loan_id', 'paid_on', 'is_prepayment', 'principal', 'interest', 'withholding_tax', 'amount_paid', 'bank_account_id', 'reference', 'journal_entry_id', 'created_by'])]
class LoanRepayment extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'is_prepayment' => 'boolean',
            'principal' => 'decimal:2',
            'interest' => 'decimal:2',
            'withholding_tax' => 'decimal:2',
            'amount_paid' => 'decimal:2',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function instalment(): HasOne
    {
        return $this->hasOne(LoanInstalment::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
