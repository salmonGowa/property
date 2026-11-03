<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One penalty assessment: which overdue invoice, under which rule, and the penalty invoice raised.
 */
#[Fillable(['invoice_id', 'penalty_invoice_id', 'penalty_rule_id', 'occurrence', 'assessed_on', 'basis', 'amount'])]
class PenaltyCharge extends Model
{
    protected function casts(): array
    {
        return [
            'assessed_on' => 'date',
            'basis' => 'decimal:2',
            'amount' => 'decimal:2',
            'occurrence' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function penaltyInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'penalty_invoice_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(PenaltyRule::class, 'penalty_rule_id');
    }
}
