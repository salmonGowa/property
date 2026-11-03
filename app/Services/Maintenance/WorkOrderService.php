<?php

namespace App\Services\Maintenance;

use App\Enums\ChargeType;
use App\Enums\InvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\UnitStatus;
use App\Enums\WorkOrderCategory;
use App\Enums\WorkOrderPriority;
use App\Enums\WorkOrderStatus;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\Billing\InvoiceService;
use App\Services\Ledger\InvalidJournalEntry;
use App\Services\Money;
use App\Services\Numbering\DocumentNumber;
use App\Services\Procurement\ProcurementActionRefused;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moves work orders from report to completion: Open, Assigned to a supplier, In Progress,
 * then Completed (or Cancelled). Damage caused by a tenant can be recharged to them.
 */
class WorkOrderService
{
    public function __construct(
        private DocumentNumber $numbers,
        private InvoiceService $invoices,
    ) {}

    /**
     * @param  array{property_id: int|string, unit_id?: int|string|null, tenant_id?: int|string|null, title: string, description?: string|null, category: WorkOrderCategory|string, priority: WorkOrderPriority|string, vendor_id?: int|string|null, estimated_cost?: string|null, tenant_chargeable?: bool, takes_unit_offline?: bool, reported_at?: CarbonInterface|string|null, due_on?: CarbonInterface|string|null, maintenance_schedule_id?: int|null}  $details
     *
     * @throws ProcurementActionRefused
     */
    public function log(array $details, ?User $by = null): WorkOrder
    {
        $priority = $details['priority'] instanceof WorkOrderPriority ? $details['priority'] : WorkOrderPriority::from($details['priority']);
        $reportedAt = Carbon::parse($details['reported_at'] ?? now());
        $unit = filled($details['unit_id'] ?? null) ? Unit::query()->findOrFail($details['unit_id']) : null;
        $vendorId = filled($details['vendor_id'] ?? null) ? (int) $details['vendor_id'] : null;
        $takesUnitOffline = (bool) ($details['takes_unit_offline'] ?? false);

        if ($unit !== null && $unit->property_id !== (int) $details['property_id']) {
            throw new ProcurementActionRefused("Unit {$unit->code} is not in the chosen property.");
        }

        if ($takesUnitOffline && $unit?->status !== UnitStatus::Vacant) {
            throw new ProcurementActionRefused('Only a vacant unit can be taken off the market for repairs.');
        }

        return DB::transaction(function () use ($details, $priority, $reportedAt, $unit, $vendorId, $takesUnitOffline, $by): WorkOrder {
            if ($takesUnitOffline) {
                $unit->update(['status' => UnitStatus::Maintenance]);
            }

            return WorkOrder::query()->create([
                'number' => $this->numbers->next(DocumentNumber::WORK_ORDER),
                'property_id' => (int) $details['property_id'],
                'unit_id' => $unit?->id,
                'tenant_id' => filled($details['tenant_id'] ?? null) ? (int) $details['tenant_id'] : null,
                'maintenance_schedule_id' => $details['maintenance_schedule_id'] ?? null,
                'title' => $details['title'],
                'description' => $details['description'] ?? null,
                'category' => $details['category'],
                'priority' => $priority,
                'status' => $vendorId ? WorkOrderStatus::Assigned : WorkOrderStatus::Open,
                'reported_at' => $reportedAt,
                'due_on' => Carbon::parse($details['due_on'] ?? $reportedAt->copy()->startOfDay()->addDays($priority->targetDays())),
                'vendor_id' => $vendorId,
                'assigned_at' => $vendorId ? now() : null,
                'estimated_cost' => filled($details['estimated_cost'] ?? null) ? Money::round((string) $details['estimated_cost']) : null,
                'tenant_chargeable' => (bool) ($details['tenant_chargeable'] ?? false),
                'takes_unit_offline' => $takesUnitOffline,
                'created_by' => $by?->id,
            ]);
        });
    }

    /**
     * @throws ProcurementActionRefused
     */
    public function assign(WorkOrder $workOrder, Vendor $vendor, ?string $estimatedCost = null): void
    {
        $this->assertUnfinished($workOrder);

        $workOrder->update([
            'vendor_id' => $vendor->id,
            'status' => $workOrder->status === WorkOrderStatus::InProgress ? WorkOrderStatus::InProgress : WorkOrderStatus::Assigned,
            'assigned_at' => now(),
            'estimated_cost' => filled($estimatedCost) ? Money::round($estimatedCost) : $workOrder->estimated_cost,
        ]);
    }

    /**
     * @throws ProcurementActionRefused
     */
    public function start(WorkOrder $workOrder): void
    {
        $this->assertUnfinished($workOrder);

        $workOrder->update(['status' => WorkOrderStatus::InProgress, 'started_at' => $workOrder->started_at ?? now()]);
    }

    /**
     * @throws ProcurementActionRefused
     */
    public function complete(WorkOrder $workOrder, string $resolution, CarbonInterface|string|null $completedAt = null): void
    {
        $this->assertUnfinished($workOrder);

        DB::transaction(function () use ($workOrder, $resolution, $completedAt): void {
            $workOrder->update([
                'status' => WorkOrderStatus::Completed,
                'resolution' => $resolution,
                'completed_at' => Carbon::parse($completedAt ?? now()),
                'started_at' => $workOrder->started_at ?? Carbon::parse($completedAt ?? now()),
            ]);

            $this->returnUnitToMarket($workOrder);
        });
    }

    /**
     * @throws ProcurementActionRefused
     */
    public function cancel(WorkOrder $workOrder, string $reason): void
    {
        $this->assertUnfinished($workOrder);

        if ($workOrder->bills()->where('status', '!=', InvoiceStatus::Void)->exists()) {
            throw new ProcurementActionRefused("{$workOrder->number} has supplier bills, so it cannot be cancelled. Complete it instead.");
        }

        if ($workOrder->purchaseOrders()->whereIn('status', [PurchaseOrderStatus::Draft, PurchaseOrderStatus::PendingApproval, PurchaseOrderStatus::Approved])->exists()) {
            throw new ProcurementActionRefused("Cancel {$workOrder->number}'s open purchase orders first.");
        }

        DB::transaction(function () use ($workOrder, $reason): void {
            $workOrder->update(['status' => WorkOrderStatus::Cancelled, 'cancel_reason' => $reason]);
            $this->returnUnitToMarket($workOrder);
        });
    }

    /**
     * Invoice the tenant for damage they caused.
     *
     * @throws ProcurementActionRefused
     * @throws InvalidJournalEntry
     */
    public function rechargeTenant(WorkOrder $workOrder, string $amount, CarbonInterface|string $dueDate, bool $vat = false, ?string $description = null): Invoice
    {
        if ($workOrder->status !== WorkOrderStatus::Completed) {
            throw new ProcurementActionRefused('Complete the work before recharging the tenant.');
        }

        if ($workOrder->tenant === null) {
            throw new ProcurementActionRefused("{$workOrder->number} has no tenant to recharge.");
        }

        if ($workOrder->rechargeInvoice !== null && $workOrder->rechargeInvoice->status !== InvoiceStatus::Void) {
            throw new ProcurementActionRefused("{$workOrder->number} was already recharged on {$workOrder->rechargeInvoice->number}.");
        }

        $description ??= "Repair recharge {$workOrder->number}: {$workOrder->title}";

        $invoice = $this->invoices->issue(
            $workOrder->tenant,
            today(),
            $dueDate,
            $description,
            [['charge_type' => ChargeType::Other, 'description' => $description, 'amount' => $amount, 'vat' => $vat]],
            $this->leaseToCharge($workOrder),
        );

        $workOrder->update(['recharge_invoice_id' => $invoice->id]);

        return $invoice;
    }

    private function leaseToCharge(WorkOrder $workOrder): ?Lease
    {
        $leases = Lease::query()->whereBelongsTo($workOrder->tenant)->active();

        return ($workOrder->unit_id ? (clone $leases)->where('unit_id', $workOrder->unit_id)->first() : null) ?? $leases->first();
    }

    private function returnUnitToMarket(WorkOrder $workOrder): void
    {
        if ($workOrder->takes_unit_offline && $workOrder->unit?->status === UnitStatus::Maintenance && $workOrder->unit->activeLease === null) {
            $workOrder->unit->update(['status' => UnitStatus::Vacant]);
        }
    }

    private function assertUnfinished(WorkOrder $workOrder): void
    {
        if (! $workOrder->status->isUnfinished()) {
            throw new ProcurementActionRefused("{$workOrder->number} is {$workOrder->status->getLabel()}.");
        }
    }
}
