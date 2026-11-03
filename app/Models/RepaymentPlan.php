<?php

namespace App\Models;

use App\Enums\RepaymentPlanStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An agreed schedule for clearing a tenant's arrears. While it is kept, arrears
 * that existed when it was agreed are not penalised.
 */
#[Fillable(['tenant_id', 'status', 'arrears_amount', 'agreed_on', 'grace_days', 'terms', 'closed_at', 'close_reason', 'created_by'])]
class RepaymentPlan extends Model
{
    use Auditable;

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'status' => RepaymentPlanStatus::class,
            'arrears_amount' => 'decimal:2',
            'agreed_on' => 'date',
            'closed_at' => 'datetime',
            'grace_days' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function instalments(): HasMany
    {
        return $this->hasMany(RepaymentInstalment::class)->orderBy('sequence');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
