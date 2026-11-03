<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['loan_id', 'drawn_on', 'amount', 'fees', 'bank_account_id', 'reference', 'journal_entry_id', 'created_by'])]
class LoanDrawdown extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'drawn_on' => 'date',
            'amount' => 'decimal:2',
            'fees' => 'decimal:2',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
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
