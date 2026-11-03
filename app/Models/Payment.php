<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['number', 'tenant_id', 'lease_id', 'received_on', 'method', 'reference', 'amount', 'status', 'deposit_account_id', 'journal_entry_id', 'reversal_journal_entry_id', 'reversed_at', 'reversed_by', 'reversal_reason', 'notes', 'created_by'])]
class Payment extends Model
{
    use Auditable;

    protected $attributes = [
        'status' => 'posted',
    ];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'received_on' => 'date',
            'reversed_at' => 'datetime',
            'amount' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function depositAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'deposit_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function mpesaTransaction(): HasOne
    {
        return $this->hasOne(MpesaTransaction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The part of the payment not yet applied to any invoice (a credit on the tenant's account).
     */
    public function unallocatedAmount(): string
    {
        if ($this->status === PaymentStatus::Reversed) {
            return '0.00';
        }

        return bcsub($this->amount, (string) $this->allocations()->sum('amount'), 2);
    }
}
