<?php

namespace App\Models;

use App\Enums\BillingFrequency;
use App\Enums\LeaseStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Database\Factories\LeaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['reference', 'tenant_id', 'unit_id', 'status', 'start_date', 'end_date', 'billing_frequency', 'rent_amount', 'service_charge', 'deposit_amount', 'charge_vat', 'due_day', 'escalation_rate', 'escalation_interval_months', 'penalty_rule_id', 'penalties_exempt', 'activated_at', 'terminated_on', 'termination_reason', 'renewed_from_id', 'notes', 'created_by'])]
class Lease extends Model
{
    /** @use HasFactory<LeaseFactory> */
    use Auditable, HasFactory;

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeaseStatus::class,
            'billing_frequency' => BillingFrequency::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'terminated_on' => 'date',
            'activated_at' => 'datetime',
            'rent_amount' => 'decimal:2',
            'service_charge' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'escalation_rate' => 'decimal:2',
            'charge_vat' => 'boolean',
            'penalties_exempt' => 'boolean',
            'due_day' => 'integer',
            'escalation_interval_months' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function penaltyRule(): BelongsTo
    {
        return $this->belongsTo(PenaltyRule::class);
    }

    /**
     * Deposit offsets: payments made from this lease's deposit.
     */
    public function depositOffsets(): HasMany
    {
        return $this->hasMany(Payment::class)->where('method', PaymentMethod::DepositOffset)->where('status', PaymentStatus::Posted);
    }

    public function depositRefunds(): HasMany
    {
        return $this->hasMany(DepositRefund::class);
    }

    /**
     * The penalty rule in force: the lease's own, else its property's, else the company default.
     *
     * @return array{rule: ?PenaltyRule, source: string}
     */
    public function effectivePenaltyRule(): array
    {
        if ($this->penalties_exempt) {
            return ['rule' => null, 'source' => 'Exempt on this lease'];
        }

        if ($this->penaltyRule?->is_active) {
            return ['rule' => $this->penaltyRule, 'source' => 'Set on this lease'];
        }

        if ($propertyRule = $this->unit->property->penaltyRule) {
            if ($propertyRule->is_active) {
                return ['rule' => $propertyRule, 'source' => "Inherited from {$this->unit->property->name}"];
            }
        }

        $default = PenaltyRule::companyDefault();

        return ['rule' => $default, 'source' => $default ? 'Company default' : 'No rule configured'];
    }

    public function renewedFrom(): BelongsTo
    {
        return $this->belongsTo(Lease::class, 'renewed_from_id');
    }

    public function renewal(): HasOne
    {
        return $this->hasOne(Lease::class, 'renewed_from_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('status', LeaseStatus::Active);
    }

    /**
     * Active leases with a fixed end date falling within the next given number of days.
     */
    #[Scope]
    protected function expiringWithin(Builder $query, int $days): Builder
    {
        return $query->active()
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', today())
            ->whereDate('end_date', '<=', today()->addDays($days));
    }

    /**
     * The last date the tenant occupies the unit: termination date, else the end date.
     */
    public function occupancyEndsOn(): ?CarbonInterface
    {
        return $this->terminated_on ?? $this->end_date;
    }
}
