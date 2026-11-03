<?php

namespace Tests\Feature\Filament\Resources;

use App\Enums\UserRole;
use App\Enums\WorkOrderStatus;
use App\Filament\Resources\MaintenanceSchedules\Pages\ManageMaintenanceSchedules;
use App\Filament\Resources\WorkOrders\Pages\CreateWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Filament\Widgets\MaintenanceStats;
use App\Models\Lease;
use App\Models\MaintenanceSchedule;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\Leasing\LeaseService;
use App\Services\Maintenance\WorkOrderService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SetsUpLedger;
use Tests\TestCase;

class MaintenanceScreensTest extends TestCase
{
    use LazilyRefreshDatabase, SetsUpLedger;

    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger();
        $this->lease = $this->draftLease(['start_date' => '2026-10-01']);
        app(LeaseService::class)->activate($this->lease);
    }

    public function test_property_manager_logs_a_work_order_from_the_unit(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::PropertyManager)->create());
        $vendor = Vendor::factory()->create();

        Livewire::withQueryParams(['unit' => $this->lease->unit_id])
            ->test(CreateWorkOrder::class)
            ->assertSchemaStateSet(['property_id' => $this->lease->unit->property_id, 'unit_id' => $this->lease->unit_id, 'tenant_id' => $this->lease->tenant_id])
            ->fillForm(['title' => 'Kitchen sink leaking', 'category' => 'plumbing', 'priority' => 'high', 'vendor_id' => $vendor->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $workOrder = WorkOrder::query()->sole();
        $this->assertSame(WorkOrderStatus::Assigned, $workOrder->status);
        $this->assertSame('2026-10-18', $workOrder->due_on->toDateString());
        $this->assertSame($this->lease->tenant_id, $workOrder->tenant_id);
    }

    public function test_work_order_pages_render(): void
    {
        $this->actingAs(User::factory()->create());
        $workOrder = $this->workOrder();

        Livewire::test(ListWorkOrders::class)->assertOk()->assertCanSeeTableRecords([$workOrder]);
        Livewire::test(ViewWorkOrder::class, ['record' => $workOrder->id])->assertOk()->assertSee('Broken window');
        Livewire::test(EditWorkOrder::class, ['record' => $workOrder->id])->assertOk();
        Livewire::test(ManageMaintenanceSchedules::class)->assertOk();
        Livewire::test(MaintenanceStats::class)->assertOk()->assertSee('Open work orders');
    }

    public function test_work_moves_from_assignment_to_completion_and_is_recharged(): void
    {
        $workOrder = $this->workOrder();
        $vendor = Vendor::factory()->create();
        $this->actingAs(User::factory()->create());

        Livewire::test(ViewWorkOrder::class, ['record' => $workOrder->id])
            ->callAction('assign', ['vendor_id' => $vendor->id, 'estimated_cost' => '6500'])
            ->assertNotified('Supplier assigned')
            ->callAction('start')
            ->callAction('complete', ['resolution' => 'Pane replaced', 'completed_at' => '2026-10-14 16:00'])
            ->assertNotified('Work order completed')
            ->callAction('recharge', ['amount' => '6500', 'vat' => false, 'due_date' => '2026-10-29'])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $workOrder->refresh();
        $this->assertSame(WorkOrderStatus::Completed, $workOrder->status);
        $this->assertSame('6500.00', $workOrder->rechargeInvoice->total);
    }

    public function test_accountant_can_view_but_not_change_work_orders(): void
    {
        $workOrder = $this->workOrder();
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());

        Livewire::test(ViewWorkOrder::class, ['record' => $workOrder->id])
            ->assertOk()
            ->assertActionHidden('assign')
            ->assertActionHidden('complete');
    }

    public function test_property_manager_schedules_planned_maintenance_and_raises_it(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::PropertyManager)->create());

        Livewire::test(ManageMaintenanceSchedules::class)
            ->callAction('create', [
                'title' => 'Tank cleaning',
                'property_id' => $this->lease->unit->property_id,
                'category' => 'plumbing',
                'priority' => 'normal',
                'frequency' => 'semi_annual',
                'next_due_on' => '2026-10-20',
                'lead_days' => 7,
                'is_active' => true,
            ])
            ->assertHasNoActionErrors()
            ->callAction('raiseDue')
            ->assertNotified('1 work order(s) raised');

        $this->assertSame('2027-04-20', MaintenanceSchedule::query()->sole()->next_due_on->toDateString());
        $this->assertSame('Tank cleaning', WorkOrder::query()->sole()->title);
    }

    private function workOrder(): WorkOrder
    {
        return app(WorkOrderService::class)->log([
            'property_id' => $this->lease->unit->property_id,
            'unit_id' => $this->lease->unit_id,
            'tenant_id' => $this->lease->tenant_id,
            'title' => 'Broken window',
            'category' => 'carpentry',
            'priority' => 'high',
            'tenant_chargeable' => true,
        ]);
    }
}
