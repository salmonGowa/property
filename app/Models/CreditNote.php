<?php

namespace App\Models;

use App\Enums\CreditNoteType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reduces what a tenant owes on an invoice: an adjustment, penalty waiver, termination credit or write-off.
 */
#[Fillable(['number', 'type', 'tenant_id', 'invoice_id', 'issue_date', 'amount', 'vat_amount', 'reason', 'journal_entry_id', 'created_by'])]
class CreditNote extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'type' => CreditNoteType::class,
            'issue_date' => 'date',
            'amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function unallocatedAmount(): string
    {
        return bcsub($this->amount, (string) $this->allocations()->sum('amount'), 2);
    }
}
