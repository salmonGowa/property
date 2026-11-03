<?php

namespace App\Models;

use App\Enums\BillingFrequency;
use App\Enums\InterestRateType;
use App\Enums\LoanStatus;
use App\Enums\LoanType;
use App\Enums\RepaymentType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A borrowing facility: senior loan, mortgage, mezzanine debt or construction line.
 */
#[Fillable(['reference', 'lender_id', 'name', 'lender_reference', 'type', 'status', 'facility_amount', 'start_date', 'first_payment_date', 'maturity_date', 'payment_frequency', 'repayment_type', 'interest_only_periods', 'balloon_amount', 'rate_type', 'base_rate_name', 'base_rate', 'margin', 'interest_rate', 'liability_account_id', 'bank_account_id', 'notes', 'closed_at', 'created_by'])]
class Loan extends Model
{
    use Auditable;

    protected $attributes = [
        'status' => 'pending',
        'interest_only_periods' => 0,
        'balloon_amount' => '0.00',
    ];

    protected function casts(): array
    {
        return [
            'type' => LoanType::class,
            'status' => LoanStatus::class,
            'payment_frequency' => BillingFrequency::class,
            'repayment_type' => RepaymentType::class,
            'rate_type' => InterestRateType::class,
            'facility_amount' => 'decimal:2',
            'balloon_amount' => 'decimal:2',
            'base_rate' => 'decimal:3',
            'margin' => 'decimal:3',
            'interest_rate' => 'decimal:3',
            'start_date' => 'date',
            'first_payment_date' => 'date',
            'maturity_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function lender(): BelongsTo
    {
        return $this->belongsTo(Lender::class);
    }

    /**
     * Properties charged as security for the loan; their valuations drive LTV.
     */
    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class)->withTimestamps();
    }

    public function drawdowns(): HasMany
    {
        return $this->hasMany(LoanDrawdown::class)->orderBy('drawn_on');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class)->orderBy('paid_on');
    }

    public function instalments(): HasMany
    {
        return $this->hasMany(LoanInstalment::class)->orderBy('sequence');
    }

    public function nextInstalment(): HasOne
    {
        return $this->hasOne(LoanInstalment::class)->whereNull('loan_repayment_id')->orderBy('sequence');
    }

    public function rateChanges(): HasMany
    {
        return $this->hasMany(LoanRateChange::class)->orderBy('effective_from');
    }

    public function covenants(): HasMany
    {
        return $this->hasMany(LoanCovenant::class);
    }

    public function accruals(): HasMany
    {
        return $this->hasMany(InterestAccrual::class);
    }

    public function liabilityAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'liability_account_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('status', LoanStatus::Active);
    }

    public function drawnAmount(): string
    {
        return bcadd((string) $this->drawdowns()->sum('amount'), '0', 2);
    }

    /**
     * How much of the facility can still be drawn.
     */
    public function undrawnAmount(): string
    {
        return bcsub((string) $this->facility_amount, $this->drawnAmount(), 2);
    }

    /**
     * Principal still owed: everything drawn less principal repaid.
     */
    public function outstandingPrincipal(): string
    {
        return bcsub($this->drawnAmount(), (string) $this->repayments()->sum('principal'), 2);
    }

    /**
     * Rate as shown to people: "13.500%" or "CBK Rate 10.750% + 3.000% = 13.750%".
     */
    public function rateDescription(): string
    {
        $rate = rtrim(rtrim((string) $this->interest_rate, '0'), '.').'%';

        if ($this->rate_type === InterestRateType::Variable && $this->base_rate !== null) {
            $strip = fn ($value): string => rtrim(rtrim((string) $value, '0'), '.').'%';

            return trim("{$this->base_rate_name} {$strip($this->base_rate)} + {$strip($this->margin)} = {$rate}");
        }

        return "{$rate} fixed";
    }
}
