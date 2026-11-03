<?php

namespace Tests\Feature\Services\Maintenance;

use App\Enums\InvoiceStatus;
use App\Enums\UnitStatus;
use App\Enums\WorkOrderCategory;
use App\Enums\WorkOrderPriority;
use App\Enums\WorkOrderStatus;
use App\Models\MaintenanceSchedule;
use App\Models\Unit;
use App\Models\Vendor;
use App\Services\Leasing\LeaseService;
use App\Services\Maintenance\MaintenanceScheduleService;
use App\Services\Maintenance\WorkOrderService;
use App\Services\Procurement\ProcurementActionRefused;
use App\Services\Procurement\SupplierBillService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\SetsUpLedger;
use Tests\TestCase;

class WorkOrderServiceTest extends TestCase
{
    use LazilyRefreshDatabase, SetsUpLedger;

    private WorkOrderService $workOrders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger();
        $this->workOrders = app(WorkOrderService::class);
    }

    public function test_due_date_follows_priority_and_a_supplier_assigns_it(): void
    {
        $unit = Unit::factory()->create();

        $workOrder = $this->workOrders->log([
            'property_id' => $unit->property_id,
            'unit_id' => $unit->id,
            'title' => 'Burst pipe',
            'category' => WorkOrderCategory::Plumbing,
            'priority' => WorkOrderPriority::Emergency,
            'vendor_id' => Vendor::factory()->create()->id,
        ]);

        $this->assertSame('WO-000001', $workOrder->number);
        $this->assertSame('2026-10-16', $workOrder->due_on->toDateString());
        $this->assertSame(WorkOrderStatus::Assigned, $workOrder->status);
    }

    public function test_vacant_unit_is_off_the_market_until_the_work_is_done(): void
    {
        $unit = Unit::factory()->create();
        $workOrder = $this->workOrders->log([
            'property_id' => $unit->property_id, 'unit_id' => $unit->id, 'title' => 'Full repaint',
            'category' => WorkOrderCategory::Painting, 'priority' => WorkOrderPriority::Low, 'takes_unit_offline' => true,
        ]);

        $this->assertSame(UnitStatus::Maintenance, $unit->refresh()->status);
        $this->assertSame(WorkOrderStatus::Open, $workOrder->status);

        $this->workOrders->start($workOrder);
        $this->workOrders->complete($workOrder, 'Two coats applied');

        $this->assertSame(WorkOrderStatus::Completed, $workOrder->refresh()->status);
        $this->assertSame(UnitStatus::Vacant, $unit->refresh()->status);
    }

    public function test_recharges_tenant_damage_and_tracks_cost_from_bills(): void
    {
        $lease = $this->draftLease(['start_date' => '2026-10-01']);
        app(LeaseService::class)->activate($lease);
        $vendor = Vendor::factory()->create();
        $workOrder = $this->workOrders->log([
            'property_id' => $lease->unit->property_id, 'unit_id' => $lease->unit_id, 'tenant_id' => $lease->tenant_id,
            'title' => 'Broken window', 'category' => WorkOrderCategory::Carpentry, 'priority' => WorkOrderPriority::High,
            'vendor_id' => $vendor->id, 'tenant_chargeable' => true,
        ]);
        app(SupplierBillService::class)->record($vendor, 'G-19', '2026-10-15', null, 'Glazing', [
            ['account_id' => $this->account('5100')->id, 'description' => 'Glass and fitting', 'amount' => '6500', 'vat' => false],
        ], workOrder: $workOrder);
        $this->workOrders->complete($workOrder, 'Pane replaced');

        $invoice = $this->workOrders->rechargeTenant($workOrder->refresh(), '6500', '2026-10-22');

        $this->assertSame('6500.00', $workOrder->actualCost());
        $this->assertSame($lease->id, $invoice->lease_id);
        $this->assertSame('6500.00', $invoice->total);
        $this->assertSame('6500.00', $this->account('4400')->balance());
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);

        $this->expectException(ProcurementActionRefused::class);
        $this->workOrders->rechargeTenant($workOrder->refresh(), '100', '2026-10-22');
    }

    public function test_billed_work_cannot_be_cancelled(): void
    {
        $workOrder = $this->workOrders->log(['property_id' => Unit::factory()->create()->property_id, 'title' => 'Gate motor', 'category' => WorkOrderCategory::Security, 'priority' => WorkOrderPriority::High]);
        app(SupplierBillService::class)->record(Vendor::factory()->create(), 'M-1', '2026-10-15', null, 'Gate motor', [
            ['account_id' => $this->account('5110')->id, 'description' => 'Motor', 'amount' => '18000', 'vat' => false],
        ], workOrder: $workOrder);

        $this->expectException(ProcurementActionRefused::class);

        $this->workOrders->cancel($workOrder, 'Not needed');
    }

    public function test_schedules_raise_one_work_order_when_within_lead_time(): void
    {
        $schedule = MaintenanceSchedule::factory()->create(['next_due_on' => '2026-10-20', 'lead_days' => 7]);
        MaintenanceSchedule::factory()->create(['next_due_on' => '2026-11-30']);

        $raised = app(MaintenanceScheduleService::class)->raiseDue('2026-10-15');

        $this->assertCount(1, $raised);
        $this->assertSame('2026-10-20', $raised[0]->due_on->toDateString());
        $this->assertSame('2027-01-20', $schedule->refresh()->next_due_on->toDateString());
        $this->assertCount(0, app(MaintenanceScheduleService::class)->raiseDue('2026-10-15'));
    }

    public function test_missed_visits_raise_a_single_work_order(): void
    {
        $schedule = MaintenanceSchedule::factory()->create(['next_due_on' => '2026-01-10', 'lead_days' => 0]);

        $this->assertCount(1, app(MaintenanceScheduleService::class)->raiseDue('2026-10-15'));
        $this->assertSame('2027-01-10', $schedule->refresh()->next_due_on->toDateString());
    }
}
