<?php

namespace App\Models;

use App\Enums\MpesaTransactionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment notification received from Safaricom's C2B confirmation callback.
 */
#[Fillable(['trans_id', 'transaction_type', 'amount', 'bill_ref_number', 'msisdn', 'payer_name', 'business_short_code', 'transacted_at', 'status', 'payment_id', 'payload'])]
class MpesaTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'status' => MpesaTransactionStatus::class,
            'amount' => 'decimal:2',
            'transacted_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
