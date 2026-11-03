<?php

namespace App\Models;

use App\Enums\ChargeType;
use App\Enums\PenaltyCapType;
use App\Enums\PenaltyMethod;
use App\Enums\PenaltyRecurrence;
use App\Models\Concerns\Auditable;
use Database\Factories\PenaltyRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Number;

/**
 * A late-payment penalty policy set by staff and attached to properties or leases.
 */
#[Fillable(['name', 'description', 'method', 'rate', 'grace_days', 'recurrence', 'recurrence_days', 'charge_types', 'minimum_overdue', 'cap_type', 'cap_value', 'effective_from', 'is_default', 'is_active'])]
class PenaltyRule extends Model
{
    /** @use HasFactory<PenaltyRuleFactory> */
    use Auditable, HasFactory;

    protected static function booted(): void
    {
        static::saved(function (PenaltyRule $rule): void {
            if ($rule->is_default && ($rule->wasChanged('is_default') || $rule->wasRecentlyCreated)) {
                PenaltyRule::query()->whereKeyNot($rule->id)->where('is_default', true)->get()->each->update(['is_default' => false]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'method' => PenaltyMethod::class,
            'recurrence' => PenaltyRecurrence::class,
            'cap_type' => PenaltyCapType::class,
            'charge_types' => 'array',
            'rate' => 'decimal:4',
            'minimum_overdue' => 'decimal:2',
            'cap_value' => 'decimal:2',
            'effective_from' => 'date',
            'grace_days' => 'integer',
            'recurrence_days' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(PenaltyCharge::class);
    }

    public static function companyDefault(): ?self
    {
        return self::query()->where('is_default', true)->where('is_active', true)->first();
    }

    public function appliesToChargeType(ChargeType $type): bool
    {
        return in_array($type->value, $this->charge_types ?? [], true);
    }

    /**
     * Plain-language summary, e.g. "5% of the overdue amount, 5 days after due, every month while unpaid".
     */
    public function summary(): string
    {
        $rate = rtrim(rtrim((string) $this->rate, '0'), '.');

        $charge = match ($this->method) {
            PenaltyMethod::Percentage => "{$rate}% of the overdue amount",
            PenaltyMethod::Flat => 'KES '.Number::format((float) $this->rate, 2),
            PenaltyMethod::DailyPercentage => "{$rate}% of the overdue amount per day late",
        };

        $when = $this->grace_days > 0 ? "{$this->grace_days} days after the due date" : 'from the day after the due date';

        $repeat = match ($this->recurrence) {
            PenaltyRecurrence::Once => 'charged once',
            PenaltyRecurrence::Monthly => 'every month while unpaid',
            PenaltyRecurrence::EveryNDays => "every {$this->recurrence_days} days while unpaid",
        };

        $cap = match ($this->cap_type) {
            PenaltyCapType::None => '',
            PenaltyCapType::Amount => ', capped at KES '.Number::format((float) $this->cap_value, 2).' per invoice',
            PenaltyCapType::PercentOfInvoice => ", capped at {$this->cap_value}% of the invoice",
        };

        return "{$charge}, {$when}, {$repeat}{$cap}";
    }
}
