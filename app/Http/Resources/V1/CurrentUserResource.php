<?php

namespace App\Http\Resources\V1;

use App\Http\Resources\Concerns\FormatsEnums;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CreditNote;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Lease;
use App\Models\Lender;
use App\Models\Loan;
use App\Models\MaintenanceSchedule;
use App\Models\MpesaTransaction;
use App\Models\Payment;
use App\Models\PenaltyRule;
use App\Models\Property;
use App\Models\PurchaseOrder;
use App\Models\RepaymentPlan;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user plus what their role allows, so the SPA can shape navigation and actions.
 * The API still enforces every policy; these flags only decide what is shown.
 *
 * @mixin User
 */
class CurrentUserResource extends JsonResource
{
    use FormatsEnums;

    /**
     * Resource keys the SPA navigates by, mapped to the model whose policy governs them.
     *
     * @var array<string, class-string>
     */
    private const array PERMISSION_MODELS = [
        'properties' => Property::class,
        'units' => Unit::class,
        'tenants' => Tenant::class,
        'leases' => Lease::class,
        'workOrders' => WorkOrder::class,
        'maintenanceSchedules' => MaintenanceSchedule::class,
        'invoices' => Invoice::class,
        'payments' => Payment::class,
        'mpesaTransactions' => MpesaTransaction::class,
        'creditNotes' => CreditNote::class,
        'penaltyRules' => PenaltyRule::class,
        'repaymentPlans' => RepaymentPlan::class,
        'vendors' => Vendor::class,
        'purchaseOrders' => PurchaseOrder::class,
        'supplierBills' => SupplierBill::class,
        'supplierPayments' => SupplierPayment::class,
        'lenders' => Lender::class,
        'loans' => Loan::class,
        'accounts' => Account::class,
        'journalEntries' => JournalEntry::class,
        'fiscalPeriods' => FiscalPeriod::class,
        'users' => User::class,
        'auditLogs' => AuditLog::class,
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => self::enumData($this->role),
            'permissions' => $this->permissions(),
        ];
    }

    /**
     * @return array<string, array{viewAny: bool, create: bool}>
     */
    private function permissions(): array
    {
        $permissions = [];

        foreach (self::PERMISSION_MODELS as $key => $model) {
            $permissions[$key] = [
                'viewAny' => $this->resource->can('viewAny', $model),
                'create' => $this->resource->can('create', $model),
            ];
        }

        return $permissions;
    }
}
