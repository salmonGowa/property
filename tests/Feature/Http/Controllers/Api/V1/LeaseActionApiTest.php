<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\LeaseStatus;
use App\Enums\PaymentMethod;
use App\Enums\UnitStatus;
use App\Enums\UserRole;
use App\Models\DepositRefund;
use App\Models\PenaltyRule;
use App\Models\User;
use App\Services\Collections\PaymentService;
use App\Services\Leasing\LeaseService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\SetsUpLedger;
use Tests\TestCase;

class LeaseActionApiTest extends TestCase
{
    use LazilyRefreshDatabase, SetsUpLedger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger();
    }

    public function test_activation_occupies_the_unit_and_reports_the_invoices_issued(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::PropertyManager)->create());
        $lease = $this->draftLease(['start_date' => '2026-09-01', 'deposit_amount' => '60000.00']);

        $this->postJson("/api/v1/leases/{$lease->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'active')
            ->assertJsonPath('data.details.invoicesCount', 3)
            ->assertJsonPath('data.details.can.terminate', true)
            ->assertJsonPath('meta.invoicesIssued', 3);

        $this->assertSame(UnitStatus::Occupied, $lease->unit->refresh()->status);
    }

    public function test_activation_onto_a_let_unit_is_refused_with_the_reason(): void
    {
        $this->actingAs(User::factory()->create());
        $first = $this->draftLease();
        app(LeaseService::class)->activate($first);
        $second = $this->draftLease(['unit_id' => $first->unit_id]);

        $this->postJson("/api/v1/leases/{$second->id}/activate")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lease' => "already let under lease {$first->reference}"]);

        $this->assertSame(LeaseStatus::Draft, $second->refresh()->status);
    }

    public function test_read_only_roles_cannot_activate(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());

        $this->postJson("/api/v1/leases/{$this->draftLease()->id}/activate")->assertForbidden();
    }

    public function test_termination_frees_the_unit_and_credits_rent_billed_beyond_the_date(): void
    {
        $this->actingAs(User::factory()->create());
        $lease = $this->draftLease(['start_date' => '2026-10-01']);
        app(LeaseService::class)->activate($lease);

        $this->postJson("/api/v1/leases/{$lease->id}/terminate", ['terminated_on' => '2026-09-30', 'reason' => 'Too early'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['terminated_on' => 'The termination date cannot be before the lease started.']);

        $this->postJson("/api/v1/leases/{$lease->id}/terminate", ['terminated_on' => '2026-10-20', 'reason' => 'Tenant relocated'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'terminated')
            ->assertJsonPath('data.terminationReason', 'Tenant relocated')
            ->assertJsonPath('meta.invoicesAdjusted', 1);

        $this->assertSame(UnitStatus::Vacant, $lease->unit->refresh()->status);
    }

    public function test_renewal_drafts_a_follow_on_lease_once(): void
    {
        $this->actingAs(User::factory()->create());
        $lease = $this->draftLease(['start_date' => '2026-10-01', 'end_date' => '2027-09-30', 'escalation_rate' => '10.00']);
        app(LeaseService::class)->activate($lease);

        $this->getJson("/api/v1/leases/{$lease->id}")
            ->assertJsonPath('data.details.renewalDefaults', ['startDate' => '2027-10-01', 'endDate' => '2028-09-30', 'rentAmount' => '33000.00']);

        $response = $this->postJson("/api/v1/leases/{$lease->id}/renew", ['start_date' => '2027-10-01', 'end_date' => '2028-09-30', 'rent_amount' => '33000'])
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'draft')
            ->assertJsonPath('data.renewedFrom.id', $lease->id)
            ->assertJsonPath('data.rentAmount', '33000.00');

        $this->assertNotSame($lease->id, $response->json('data.id'));
        $this->postJson("/api/v1/leases/{$lease->id}/renew", ['start_date' => '2027-10-01', 'rent_amount' => '33000'])->assertForbidden();
    }

    public function test_penalty_rule_can_change_on_an_active_lease(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::FinanceManager)->create());
        $lease = $this->draftLease();
        app(LeaseService::class)->activate($lease);
        $rule = PenaltyRule::factory()->create(['name' => 'Commercial 10%']);

        $this->putJson("/api/v1/leases/{$lease->id}/penalty-rule", ['penalties_exempt' => false, 'penalty_rule_id' => $rule->id])
            ->assertOk()
            ->assertJsonPath('data.details.penalty.source', 'Set on this lease');

        $this->putJson("/api/v1/leases/{$lease->id}/penalty-rule", ['penalties_exempt' => true, 'penalty_rule_id' => $rule->id])
            ->assertOk()
            ->assertJsonPath('data.penaltyRuleId', null)
            ->assertJsonPath('data.details.penalty.source', 'Exempt on this lease');
    }

    public function test_finance_settles_the_deposit_of_a_terminated_lease(): void
    {
        $lease = $this->draftLease(['start_date' => '2026-10-01', 'deposit_amount' => '60000.00']);
        app(LeaseService::class)->activate($lease);
        app(PaymentService::class)->record($lease->tenant, today(), PaymentMethod::BankTransfer, '90000', 'EFT-1');
        app(LeaseService::class)->terminate($lease, '2026-10-31', 'Tenant gave notice');
        $this->travelTo('2026-11-02');

        $this->actingAs(User::factory()->withRole(UserRole::PropertyManager)->create())
            ->postJson("/api/v1/leases/{$lease->id}/deposit-settlement", ['date' => '2026-10-31'])
            ->assertForbidden();

        $this->actingAs(User::factory()->withRole(UserRole::FinanceManager)->create())
            ->getJson("/api/v1/leases/{$lease->id}")
            ->assertJsonPath('data.details.depositHeld', '60000.00')
            ->assertJsonPath('data.details.can.settleDeposit', true);

        $this->postJson("/api/v1/leases/{$lease->id}/deposit-settlement", [
            'date' => '2026-10-31',
            'deductions' => [['description' => 'Repaint living room', 'amount' => '8000']],
            'refund_method' => 'mpesa',
            'refund_reference' => 'REFUND001',
        ])
            ->assertOk()
            ->assertJsonPath('data', ['held' => '60000.00', 'applied' => '8000.00', 'refunded' => '52000.00', 'stillHeld' => '0.00']);

        $this->assertSame('52000.00', DepositRefund::query()->sole()->amount);
        $this->postJson("/api/v1/leases/{$lease->id}/deposit-settlement", ['date' => '2026-10-31'])
            ->assertJsonValidationErrors(['lease' => 'No deposit is held for this lease.']);
    }

    public function test_deposit_refunds_only_go_out_by_disbursable_methods(): void
    {
        $this->actingAs(User::factory()->create());
        $lease = $this->draftLease(['status' => LeaseStatus::Terminated]);

        $this->postJson("/api/v1/leases/{$lease->id}/deposit-settlement", ['date' => '2026-10-31', 'refund_method' => 'deposit_offset', 'deductions' => [['description' => '', 'amount' => '0']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['refund_method', 'deductions.0.description', 'deductions.0.amount']);
    }
}
