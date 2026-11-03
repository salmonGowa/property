<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\WithholdingTaxType;
use App\Models\Concerns\Auditable;
use App\Services\Numbering\DocumentNumber;
use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supplier or contractor the company buys from.
 */
#[Fillable(['number', 'name', 'kra_pin', 'categories', 'contact_person', 'phone', 'email', 'address', 'is_vat_registered', 'withholding_tax_type', 'payment_method', 'bank_name', 'bank_branch', 'bank_account_name', 'bank_account_number', 'mpesa_number', 'payment_terms_days', 'notes', 'is_active'])]
class Vendor extends Model
{
    /** @use HasFactory<VendorFactory> */
    use Auditable, HasFactory;

    protected $attributes = [
        'withholding_tax_type' => 'none',
        'payment_terms_days' => 30,
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::creating(function (Vendor $vendor): void {
            $vendor->number ??= app(DocumentNumber::class)->next(DocumentNumber::VENDOR);
        });
    }

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'is_vat_registered' => 'boolean',
            'withholding_tax_type' => WithholdingTaxType::class,
            'payment_method' => PaymentMethod::class,
            'is_active' => 'boolean',
        ];
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(SupplierBill::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    /**
     * What the company still owes this supplier across its unpaid bills.
     */
    public function balance(): string
    {
        $owed = $this->bills()
            ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::PartiallyPaid])
            ->selectRaw('COALESCE(SUM(payable_amount - amount_paid), 0) as owed')
            ->value('owed');

        return bcadd((string) $owed, '0', 2);
    }

    /**
     * Where the supplier is paid, e.g. "KCB Kilimani 1234567890" or "M-Pesa 0712345678".
     */
    public function paymentDetails(): ?string
    {
        return match ($this->payment_method) {
            PaymentMethod::BankTransfer, PaymentMethod::Cheque => trim("{$this->bank_name} {$this->bank_branch} {$this->bank_account_number}") ?: null,
            PaymentMethod::Mpesa => $this->mpesa_number ? "M-Pesa {$this->mpesa_number}" : null,
            default => null,
        };
    }
}
