<?php

namespace Tests\Feature\Services\Arrears;

use App\Enums\PaymentMethod;
use App\Enums\RepaymentPlanStatus;
use App\Models\Lease;
use App\Services\Arrears\ArrearsAging;
use App\Services\Arrears\RepaymentPlanService;
use App\Services\Collections\PaymentService;
use App\Services\Leasing\LeaseActionRefused;
use App\Services\Leasing\LeaseService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SetsUpLedger;
use Tests\TestCase;

class RepaymentPlanServiceTest extends TestCase
{
    use LazilyRefreshDatabase, SetsUpLedger;

    private Lease $lease;

    private RepaymentPlanService $plans;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger('2026-10-15');
        $this->lease = $this->draftLease(['start_date' => '2026-08-01']);
        app(LeaseService::class)->activate($this->lease);
        $this->plans = app(RepaymentPlanService::class);
    }

    public function test_splits_the_arrears_into_equal_instalments_with_the_remainder_last(): void
    {
        $plan = $this->plans->create($this->lease->tenant, '2026-10-31', 7);

        $this->assertSame('90000.00', $plan->arrears_amount);
        $this->assertSame(['12857.14', '12857.14', '12857.14', '12857.14', '12857.14', '12857.14', '12857.16'], $plan->instalments->pluck('amount')->all());
        $this->assertSame('2027-04-30', $plan->instalments->last()->due_date->toDateString());
    }

    public function test_plan_is_defaulted_when_an_instalment_is_missed_past_grace(): void
    {
        $plan = $this->plans->create($this->lease->tenant, '2026-10-31', 3, graceDays: 3);

        $this->assertSame(RepaymentPlanStatus::Active, $this->plans->review($plan, Carbon::parse('2026-11-03')));
        $this->assertSame(RepaymentPlanStatus::Defaulted, $this->plans->review($plan, Carbon::parse('2026-11-04')));
    }

    public function test_plan_kept_while_instalments_are_paid_and_completed_when_arrears_clear(): void
    {
        $plan = $this->plans->create($this->lease->tenant, '2026-10-31', 2);
        $payments = app(PaymentService::class);

        $payments->record($this->lease->tenant, '2026-10-30', PaymentMethod::Cash, '45000');
        $this->assertSame(RepaymentPlanStatus::Active, $this->plans->review($plan, Carbon::parse('2026-11-20')));

        $payments->record($this->lease->tenant, '2026-11-30', PaymentMethod::Cash, '45000');
        $this->assertSame(RepaymentPlanStatus::Completed, $this->plans->review($plan, Carbon::parse('2026-12-01')));
    }

    public function test_refuses_a_second_active_plan(): void
    {
        $this->plans->create($this->lease->tenant, '2026-10-31', 3);

        $this->expectException(LeaseActionRefused::class);

        $this->plans->create($this->lease->tenant, '2026-10-31', 3);
    }

    public function test_aging_groups_balances_by_days_overdue(): void
    {
        $buckets = app(ArrearsAging::class)->forTenant($this->lease->tenant);

        $this->assertSame([
            'current' => '0.00',
            'days_1_30' => '30000.00',
            'days_31_60' => '30000.00',
            'days_61_90' => '30000.00',
            'days_over_90' => '0.00',
        ], $buckets);
    }
}
