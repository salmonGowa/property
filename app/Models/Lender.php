<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\LenderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bank or other financier the company borrows from.
 */
#[Fillable(['name', 'branch', 'contact_person', 'phone', 'email', 'interest_withholding_rate', 'notes', 'is_active'])]
class Lender extends Model
{
    /** @use HasFactory<LenderFactory> */
    use Auditable, HasFactory;

    protected $attributes = [
        'interest_withholding_rate' => '0.00',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'interest_withholding_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}
