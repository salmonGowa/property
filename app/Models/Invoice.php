<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['number', 'tenant_id', 'lease_id', 'billing_key', 'description', 'issue_date', 'due_date', 'period_start', 'period_end', 'status', 'subtotal', 'vat_amount', 'total', 'amount_paid', 'journal_entry_id', 'void_journal_entry_id', 'voided_at', 'voided_by', 'void_reason', 'created_by'])]
class Invoice extends Model
{
    use Auditable;

    protected $attributes = [
        'status' => 'unpaid',
        'amount_paid' => '0.00',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
            'voided_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
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

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    /**
     * Penalties assessed because this invoice was paid late.
     */
    public function penaltyCharges(): HasMany
    {
        return $this->hasMany(PenaltyCharge::class);
    }

    /**
     * Total settled by credit notes (the rest of amount_paid came from payments).
     */
    public function amountCredited(): string
    {
        return bcadd('0', (string) $this->allocations()->whereNotNull('credit_note_id')->sum('amount'), 2);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    #[Scope]
    protected function outstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::PartiallyPaid]);
    }

    #[Scope]
    protected function overdue(Builder $query): Builder
    {
        return $query->outstanding()->whereDate('due_date', '<', today());
    }

    public function balanceDue(): string
    {
        return $this->status === InvoiceStatus::Void ? '0.00' : bcsub($this->total, $this->amount_paid, 2);
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, [InvoiceStatus::Unpaid, InvoiceStatus::PartiallyPaid], true)
            && $this->due_date->lt(today());
    }
}
