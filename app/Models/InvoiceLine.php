<?php

namespace App\Models;

use App\Enums\ChargeType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['charge_type', 'description', 'amount', 'vat_rate', 'vat_amount', 'account_id'])]
class InvoiceLine extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'charge_type' => ChargeType::class,
            'amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function total(): string
    {
        return bcadd($this->amount, $this->vat_amount, 2);
    }
}
