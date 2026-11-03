<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An adjustment of the allowance for doubtful debts to match the aged arrears.
 */
#[Fillable(['as_of', 'rates', 'buckets', 'required', 'previous', 'adjustment', 'journal_entry_id', 'created_by'])]
class BadDebtProvision extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'as_of' => 'date',
            'rates' => 'array',
            'buckets' => 'array',
            'required' => 'decimal:2',
            'previous' => 'decimal:2',
            'adjustment' => 'decimal:2',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
