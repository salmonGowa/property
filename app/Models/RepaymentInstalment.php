<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['repayment_plan_id', 'sequence', 'due_date', 'amount'])]
class RepaymentInstalment extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'sequence' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(RepaymentPlan::class, 'repayment_plan_id');
    }
}
