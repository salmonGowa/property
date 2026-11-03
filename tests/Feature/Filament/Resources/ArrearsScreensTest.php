<?php

namespace Tests\Feature\Filament\Resources;

use App\Enums\CreditNoteType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\RepaymentPlanStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Leases\Pages\ViewLease;
use App\Filament\Resources\PenaltyRules\Pages\ManagePenaltyRules;
use App\Filament\Resources\Tenants\Pages\ViewTenant;
use App\Models\CreditNote;
use App\Models\Lease;
use App\Models\PenaltyRule;
use App\Models\User;
use App\Services\Arrears\PenaltyService;
use App\Services\Collections\PaymentService;
use App\Services\Leasing\LeaseService;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SetsUpLedger;
use Tests\TestCase;

class ArrearsScreensTest extends TestCase
{
    use LazilyRefreshDatabase, SetsUpLedger;

    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger();
        $this->lease = $this->draftLease(['start_date' => '2026-10-01', 'deposit_amount' => '30000.00']);
        app(LeaseService::class)->activate($this->lease);
    }

    public function test_property_manager_creates_a_default_penalty_rule(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::PropertyManager)->create());

        Livewire::test(ManagePenaltyRules::class)
            ->callAction('create', [
                'name' => 'Commercial: KES 2,500 monthly',
                'is_active' => true,
                'is_default' => true,
                'method' => 'flat',
                'rate' => '2500',
                'charge_types' => ['rent'],
                'grace_days' => 7,
                'recurrence' => 'monthly',
                'effective_from' => '2026-10-01',
                'minimum_overdue' => '0',
                'cap_type' => 'none',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('KES 2,500.00, 7 days after the due date, every month while unpaid', PenaltyRule::companyDefault()->summary());
    }

    public function test_accountant_cannot_change_penalty_rules(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());

        Livewire::test(ManagePenaltyRules::class)->assertActionHidden('create');
    }

    public function test_finance_manager_waives_a_penalty(): void
    {
        PenaltyRule::factory()->companyDefault()->create();
        $penaltyInvoice = app(PenaltyService::class)->assess(today())->invoices[0];
        $this->actingAs(User::factory()->withRole(UserRole::FinanceManager)->create());

        Livewire::test(ViewInvoice::class, ['record' => $penaltyInvoice->id])
            ->callAction('waivePenalty', ['amount' => $penaltyInvoice->total, 'issue_date' => '2026-10-15', 'reason' => 'First offence'])
            ->assertNotified();

        $this->assertSame(CreditNoteType::PenaltyWaiver, CreditNote::query()->sole()->type);
        $this->assertSame(InvoiceStatus::Paid, $penaltyInvoice->refresh()->status);
    }

    public function test_accountant_cannot_issue_credit_notes(): void
    {
        $invoice = $this->lease->invoices()->whereNotNull('period_start')->sole();
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
            ->assertActionHidden('issueCreditNote')
            ->assertActionHidden('writeOff');
    }

    public function test_accountant_agrees_a_repayment_plan_from_the_tenant_page(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());

        Livewire::test(ViewTenant::class, ['record' => $this->lease->tenant_id])
            ->callAction('agreeRepaymentPlan', ['instalments' => 3, 'interval_months' => 1, 'first_due_date' => '2026-11-05', 'grace_days' => 3])
            ->assertNotified('Repayment plan agreed');

        $this->assertSame(RepaymentPlanStatus::Active, $this->lease->tenant->repaymentPlans()->sole()->status);
    }

    public function test_finance_manager_settles_the_deposit_after_termination(): void
    {
        app(PaymentService::class)->record($this->lease->tenant, today(), PaymentMethod::BankTransfer, '60000', 'EFT-9');
        app(LeaseService::class)->terminate($this->lease, '2026-10-31', 'Notice given');
        $this->actingAs(User::factory()->withRole(UserRole::FinanceManager)->create());
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(ViewLease::class, ['record' => $this->lease->id])
            ->callAction('settleDeposit', [
                'date' => '2026-10-15',
                'deductions' => [['description' => 'Broken window', 'amount' => '4500']],
                'refund_method' => 'mpesa',
                'refund_reference' => 'SRF0000001',
            ])
            ->assertNotified('Deposit settled');

        $undoRepeaterFake();

        $this->assertSame('25500.00', $this->lease->depositRefunds()->sole()->amount);
    }

    public function test_property_manager_changes_the_penalty_rule_of_an_active_lease(): void
    {
        $rule = PenaltyRule::factory()->create(['name' => 'Lenient']);
        $this->actingAs(User::factory()->withRole(UserRole::PropertyManager)->create());

        Livewire::test(ViewLease::class, ['record' => $this->lease->id])
            ->callAction('changePenaltyRule', ['penalty_rule_id' => $rule->id, 'penalties_exempt' => false])
            ->assertNotified('Penalty rule updated');

        $this->assertSame('Set on this lease', $this->lease->refresh()->effectivePenaltyRule()['source']);
    }
}
