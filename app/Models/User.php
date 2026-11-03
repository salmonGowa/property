<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'purchase_approval_limit'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, Notifiable;

    /**
     * Attributes whose values are masked in the audit log.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['password', 'remember_token'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'purchase_approval_limit' => 'decimal:2',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->role !== null;
    }

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * Read access to the chart of accounts, journals and financial reports.
     */
    public function canViewLedger(): bool
    {
        return $this->hasRole(UserRole::Admin, UserRole::FinanceManager, UserRole::Accountant, UserRole::Auditor);
    }

    /**
     * Control over the chart of accounts, fiscal periods and reversals.
     */
    public function canManageLedger(): bool
    {
        return $this->hasRole(UserRole::Admin, UserRole::FinanceManager);
    }

    /**
     * Read access to properties, units, tenants and leases.
     */
    public function canViewProperties(): bool
    {
        return $this->hasRole(UserRole::Admin, UserRole::PropertyManager, UserRole::FinanceManager, UserRole::Accountant, UserRole::Auditor);
    }

    /**
     * Create and change properties, units, tenants and leases.
     */
    public function canManageProperties(): bool
    {
        return $this->hasRole(UserRole::Admin, UserRole::PropertyManager);
    }

    /**
     * Read access to invoices and payments.
     */
    public function canViewBilling(): bool
    {
        return $this->hasRole(UserRole::Admin, UserRole::FinanceManager, UserRole::Accountant, UserRole::PropertyManager, UserRole::Auditor);
    }

    /**
     * Issue invoices, record payments and match M-Pesa transactions.
     */
    public function canManageBilling(): bool
    {
        return $this->hasRole(UserRole::Admin, UserRole::FinanceManager, UserRole::Accountant);
    }

    /**
     * Read access to work orders and maintenance schedules.
     */
    public function canViewMaintenance(): bool
    {
        return $this->hasRole(UserRole::Admin, UserRole::PropertyManager, UserRole::FinanceManager, UserRole::Accountant, UserRole::Auditor);
    }

    /**
     * Log, assign and complete work orders; plan preventive maintenance.
     */
    public function canManageMaintenance(): bool
    {
        return $this->hasRole(UserRole::Admin, UserRole::PropertyManager);
    }

    /**
     * Read access to suppliers, purchase orders, bills and supplier payments.
     */
    public function canViewProcurement(): bool
    {
        return $this->hasRole(UserRole::Admin, UserRole::FinanceManager, UserRole::Accountant, UserRole::PropertyManager, UserRole::WarehouseManager, UserRole::Auditor);
    }

    /**
     * Raise purchase orders and submit them for approval.
     */
    public function canRaisePurchaseOrders(): bool
    {
        return $this->hasRole(UserRole::Admin, UserRole::FinanceManager, UserRole::Accountant, UserRole::PropertyManager, UserRole::WarehouseManager);
    }

    /**
     * Maintain suppliers and enter supplier bills.
     */
    public function canManagePayables(): bool
    {
        return $this->hasRole(UserRole::Admin, UserRole::FinanceManager, UserRole::Accountant);
    }

    /**
     * Whether the user's approval limit covers a purchase of this amount.
     * Administrators may approve any amount; everyone else needs a limit set on their user.
     */
    public function canApprovePurchase(string $amount): bool
    {
        if ($this->hasRole(UserRole::Admin)) {
            return true;
        }

        return $this->purchase_approval_limit !== null && bccomp($amount, (string) $this->purchase_approval_limit, 2) <= 0;
    }
}
