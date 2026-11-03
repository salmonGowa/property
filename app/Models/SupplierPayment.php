<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money paid to a supplier (a payment voucher), settling one or more of their bills.
 */
#[Fillable(['number', 'vendor_id', 'paid_on', 'method', 'reference', 'amount', 'status', 'paid_from_account_id', 'notes', 'journal_entry_id', 'reversal_journal_entry_id', 'reversed_at', 'reversed_by', 'reversal_reason', 'created_by'])]
class SupplierPayment extends Model
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
            'paid_on' => 'date',
            'reversed_at' => 'datetime',
            'amount' => 'decimal:2',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    public function paidFromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'paid_from_account_id');
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
