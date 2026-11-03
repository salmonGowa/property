<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\LeaseStatus;
use App\Enums\PaymentStatus;
use App\Enums\TenantType;
use App\Models\Concerns\Auditable;
use App\Services\Numbering\DocumentNumber;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_number', 'type', 'name', 'id_number', 'registration_number', 'kra_pin', 'email', 'phone', 'alternate_phone', 'postal_address', 'contact_person', 'contact_person_phone', 'emergency_contact_name', 'emergency_contact_phone', 'notes', 'is_active'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use Auditable, HasFactory;

    /**
     * Personal identifiers are masked in the audit log (Data Protection Act, 2019).
     *
     * @var list<string>
     */
    protected array $auditExclude = ['id_number'];

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            $tenant->account_number ??= app(DocumentNumber::class)->next(DocumentNumber::TENANT);
        });
    }

    protected function casts(): array
    {
        return [
            'type' => TenantType::class,
            'id_number' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    public function activeLeases(): HasMany
    {
        return $this->leases()->where('status', LeaseStatus::Active);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function repaymentPlans(): HasMany
    {
        return $this->hasMany(RepaymentPlan::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TenantDocument::class);
    }

    /**
     * Adds billed_total, paid_total and credited_total columns so balances can be listed and sorted.
     */
    #[Scope]
    protected function withBalance(Builder $query): Builder
    {
        return $query
            ->withSum(['invoices as billed_total' => fn (Builder $invoices) => $invoices->where('status', '!=', InvoiceStatus::Void)], 'total')
            ->withSum(['payments as paid_total' => fn (Builder $payments) => $payments->where('status', PaymentStatus::Posted)], 'amount')
            ->withSum('creditNotes as credited_total', 'amount');
    }

    /**
     * Amount owed by the tenant; negative when they are in credit.
     */
    public function balance(): string
    {
        $billed = $this->billed_total ?? $this->invoices()->where('status', '!=', InvoiceStatus::Void)->sum('total');
        $paid = $this->paid_total ?? $this->payments()->where('status', PaymentStatus::Posted)->sum('amount');
        $credited = $this->credited_total ?? $this->creditNotes()->sum('amount');

        return bcsub(bcsub((string) ($billed ?? '0'), (string) ($paid ?? '0'), 2), (string) ($credited ?? '0'), 2);
    }
}
