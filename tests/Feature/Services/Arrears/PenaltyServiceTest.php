<?php

namespace Tests\Feature\Services\Arrears;

use App\Enums\ChargeType;
use App\Enums\PaymentMethod;
use App\Enums\PenaltyCapType;
use App\Enums\PenaltyMethod;
use App\Enums\PenaltyRecurrence;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\PenaltyRule;
use App\Services\Arrears\PenaltyRunResult;
use App\Services\Arrears\PenaltyService;
use App\Services\Arrears\RepaymentPlanService;
use App\Services\Collections\PaymentService;
use App\Services\Leasing\LeaseService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SetsUpLedger;
use Tests\TestCase;

/**
 * The October invoice (KES 30,000 rent) falls due on 5 Oct 2026.
 */
class PenaltyServiceTest extends TestCase
{
    use LazilyRefreshDatabase, SetsUpLedger;

    private Lease $lease;

    private PenaltyService $penalties;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger('2026-10-01');
        $this->lease = $this->draftLease(['start_date' => '2026-10-01']);
        app(LeaseService::class)->activate($this->lease);
        $this->penalties = app(PenaltyService::class);
    }

    public function test_percentage_penalty_is_charged_once_the_day_after_the_grace_period(): void
    {
        PenaltyRule::factory()->companyDefault()->create(['rate' => '5', 'grace_days' => 5]);

        $this->assertCount(0, $this->assessOn('2026-10-10')->invoices);
        $result = $this->assessOn('2026-10-11');
        $again = $this->assessOn('2026-10-11');

        $this->assertSame('1500.00', $result->invoices[0]->total);
        $this->assertCount(0, $again->invoices);
        $this->assertSame('1500.00', $this->account('4300')->balance());
    }

    public function test_monthly_penalty_catches_up_missed_assessments_in_one_invoice(): void
    {
        PenaltyRule::factory()->companyDefault()->create(['recurrence' => PenaltyRecurrence::Monthly, 'grace_days' => 5]);

        $result = $this->assessOn('2026-12-11');

        $this->assertSame(['1500.00', '1500.00', '1500.00'], $result->invoices[0]->lines->pluck('amount')->all());
    }

    public function test_flat_fee_with_cap_stops_at_the_cap(): void
    {
        PenaltyRule::factory()->companyDefault()->create([
            'method' => PenaltyMethod::Flat, 'rate' => '1000', 'grace_days' => 0, 'recurrence' => PenaltyRecurrence::Monthly,
            'cap_type' => PenaltyCapType::Amount, 'cap_value' => '2500',
        ]);

        $this->assessOn('2026-11-06');
        $later = $this->assessOn('2027-01-06');

        $this->assertSame(['500.00'], $later->invoices[0]->lines->pluck('amount')->all());
        $this->assertCount(0, $this->assessOn('2027-02-06')->invoices);
    }

    public function test_daily_percentage_accrues_for_each_day_late(): void
    {
        PenaltyRule::factory()->companyDefault()->create([
            'method' => PenaltyMethod::DailyPercentage, 'rate' => '0.1', 'grace_days' => 5, 'recurrence' => PenaltyRecurrence::Monthly,
        ]);

        $first = $this->assessOn('2026-10-11');
        $second = $this->assessOn('2026-11-11');

        $this->assertSame('180.00', $first->invoices[0]->total);
        $this->assertSame('930.00', $second->invoices[0]->total);
    }

    public function test_penalty_is_calculated_only_on_the_charge_types_the_rule_covers(): void
    {
        $lease = $this->draftLease(['start_date' => '2026-10-01', 'rent_amount' => '30000.00', 'service_charge' => '3000.00']);
        app(LeaseService::class)->activate($lease);
        PenaltyRule::factory()->companyDefault()->create(['charge_types' => [ChargeType::ServiceCharge->value]]);

        $result = $this->assessOn('2026-10-11');

        $this->assertSame(['150.00'], collect($result->invoices)->pluck('total')->all());
    }

    public function test_balance_below_the_minimum_is_not_penalised(): void
    {
        PenaltyRule::factory()->companyDefault()->create(['minimum_overdue' => '500']);
        app(PaymentService::class)->record($this->lease->tenant, '2026-10-03', PaymentMethod::Cash, '29600');

        $this->assertCount(0, $this->assessOn('2026-10-11')->invoices);
    }

    public function test_changing_a_rule_never_charges_assessments_dated_before_it_took_effect(): void
    {
        PenaltyRule::factory()->companyDefault()->create(['recurrence' => PenaltyRecurrence::Monthly, 'effective_from' => '2026-11-01']);

        $result = $this->assessOn('2026-11-11');

        $this->assertSame(['1500.00'], $result->invoices[0]->lines->pluck('amount')->all());
    }

    public function test_lease_rule_overrides_property_rule_which_overrides_company_default(): void
    {
        PenaltyRule::factory()->companyDefault()->create(['rate' => '1']);
        $propertyRule = PenaltyRule::factory()->create(['rate' => '2']);
        $this->lease->unit->property->update(['penalty_rule_id' => $propertyRule->id]);

        $this->assertSame($propertyRule->id, $this->lease->refresh()->effectivePenaltyRule()['rule']->id);

        $leaseRule = PenaltyRule::factory()->create(['rate' => '3']);
        $this->lease->update(['penalty_rule_id' => $leaseRule->id]);

        $this->assertSame('900.00', $this->assessOn('2026-10-11')->invoices[0]->total);
    }

    public function test_exempt_lease_is_never_penalised(): void
    {
        PenaltyRule::factory()->companyDefault()->create();
        $this->lease->update(['penalties_exempt' => true]);

        $this->assertCount(0, $this->assessOn('2026-10-11')->invoices);
    }

    public function test_penalty_invoices_do_not_attract_further_penalties(): void
    {
        PenaltyRule::factory()->companyDefault()->create(['recurrence' => PenaltyRecurrence::Monthly, 'grace_days' => 0]);

        $this->assessOn('2026-10-06');
        $later = $this->assessOn('2026-11-07');

        $this->assertSame(1, collect($later->invoices)->count());
        $this->assertSame(2, Invoice::query()->whereHas('lines', fn ($lines) => $lines->where('charge_type', ChargeType::Penalty))->count());
    }

    public function test_arrears_under_an_active_repayment_plan_are_not_penalised(): void
    {
        PenaltyRule::factory()->companyDefault()->create();
        $this->travelTo('2026-10-08');
        app(RepaymentPlanService::class)->create($this->lease->tenant, '2026-10-31', 3);

        $this->assertCount(0, $this->assessOn('2026-10-11')->invoices);
    }

    private function assessOn(string $date): PenaltyRunResult
    {
        $this->travelTo($date);

        return $this->penalties->assess(Carbon::parse($date));
    }
}
