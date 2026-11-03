<?php

namespace Tests\Feature\Services\Leasing;

use App\Enums\InvoiceStatus;
use App\Enums\LeaseStatus;
use App\Enums\UnitStatus;
use App\Models\Invoice;
use App\Services\Leasing\LeaseActionRefused;
use App\Services\Leasing\LeaseService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\SetsUpLedger;
use Tests\TestCase;

class LeaseServiceTest extends TestCase
{
    use LazilyRefreshDatabase, SetsUpLedger;

    private LeaseService $leases;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger();
        $this->leases = app(LeaseService::class);
    }

    public function test_activation_occupies_the_unit_and_bills_deposit_and_rent_due_so_far(): void
    {
        $lease = $this->draftLease(['start_date' => '2026-09-01', 'deposit_amount' => '60000.00']);

        $this->leases->activate($lease);

        $this->assertSame(LeaseStatus::Active, $lease->refresh()->status);
        $this->assertSame(UnitStatus::Occupied, $lease->unit->refresh()->status);
        $this->assertSame(
            ['60000.00', '30000.00', '30000.00'],
            Invoice::query()->orderBy('id')->pluck('total')->all(),
        );
    }

    public function test_refuses_to_activate_onto_a_unit_that_is_already_let(): void
    {
        $first = $this->draftLease();
        $this->leases->activate($first);
        $second = $this->draftLease(['unit_id' => $first->unit_id]);

        $this->expectException(LeaseActionRefused::class);
        $this->expectExceptionMessage($first->reference);

        $this->leases->activate($second);
    }

    public function test_termination_frees_the_unit(): void
    {
        $lease = $this->draftLease();
        $this->leases->activate($lease);

        $this->leases->terminate($lease, '2026-10-20', 'Tenant relocated');

        $this->assertSame(LeaseStatus::Terminated, $lease->refresh()->status);
        $this->assertSame('2026-10-20', $lease->terminated_on->toDateString());
        $this->assertSame(UnitStatus::Vacant, $lease->unit->refresh()->status);
    }

    public function test_activating_a_renewal_ends_the_original_the_day_before_without_a_new_deposit(): void
    {
        $original = $this->draftLease(['start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'deposit_amount' => '30000.00']);
        $this->leases->activate($original);

        $renewal = $this->leases->renew($original, '2026-11-01', '2027-10-31', '32000.00');
        $this->leases->activate($renewal, billUpTo: today()->addMonth());

        $this->assertSame(LeaseStatus::Terminated, $original->refresh()->status);
        $this->assertSame('2026-10-31', $original->terminated_on->toDateString());
        $this->assertSame(LeaseStatus::Active, $renewal->refresh()->status);
        $this->assertSame(['32000.00'], $renewal->invoices()->pluck('total')->all());
    }

    public function test_terminating_mid_month_credits_unused_days_of_rent_already_billed(): void
    {
        $lease = $this->draftLease(['start_date' => '2026-10-01']);
        $this->leases->activate($lease);

        $adjustments = $this->leases->terminate($lease, '2026-10-20', 'Relocated');

        $this->assertCount(1, $adjustments);
        $this->assertSame('10645.16', $adjustments[0]->amount);
        $this->assertSame('19354.84', $lease->tenant->refresh()->balance());
    }

    public function test_terminating_voids_unpaid_invoices_for_periods_after_the_termination_date(): void
    {
        $lease = $this->draftLease(['start_date' => '2026-10-01']);
        $this->leases->activate($lease, billUpTo: today()->addMonth());
        $november = $lease->invoices()->whereDate('period_start', '2026-11-01')->sole();

        $this->leases->terminate($lease, '2026-10-31', 'End of term');

        $this->assertSame(InvoiceStatus::Void, $november->refresh()->status);
        $this->assertSame('30000.00', $lease->tenant->refresh()->balance());
    }
}
