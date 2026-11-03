<?php

namespace Tests\Feature\Filament\Resources;

use App\Enums\InvoiceStatus;
use App\Enums\MpesaTransactionStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\MpesaTransactions\Pages\ManageMpesaTransactions;
use App\Filament\Resources\Tenants\Pages\ViewTenant;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Collections\MpesaC2bService;
use App\Services\Leasing\LeaseService;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SetsUpLedger;
use Tests\TestCase;

class BillingScreensTest extends TestCase
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

    public function test_accountant_records_a_payment_from_the_tenant_page(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());

        Livewire::test(ViewTenant::class, ['record' => $this->lease->tenant_id])
            ->callAction('recordPayment', [
                'method' => 'mpesa',
                'amount' => '30000',
                'received_on' => '2026-10-14',
                'reference' => 'sjk3h5t9qw',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Receipt RCT-000001 recorded');

        $this->assertSame(InvoiceStatus::Paid, $this->lease->invoices()->sole()->status);
    }

    public function test_mpesa_payment_requires_the_transaction_code(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());

        Livewire::test(ViewTenant::class, ['record' => $this->lease->tenant_id])
            ->callAction('recordPayment', ['method' => 'mpesa', 'amount' => '100', 'received_on' => '2026-10-14', 'reference' => null])
            ->assertHasActionErrors(['reference' => 'required']);
    }

    public function test_property_manager_cannot_record_payments(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::PropertyManager)->create());

        Livewire::test(ViewTenant::class, ['record' => $this->lease->tenant_id])
            ->assertActionHidden('recordPayment');
    }

    public function test_run_billing_action_issues_due_invoices(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());

        Livewire::test(ListInvoices::class)
            ->callAction('runBilling', ['up_to' => '2026-12-01'])
            ->assertNotified('2 invoice(s) issued');

        $this->assertSame(3, $this->lease->invoices()->count());
    }

    public function test_accountant_raises_a_manual_utility_invoice(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(CreateInvoice::class)
            ->fillForm([
                'tenant_id' => $this->lease->tenant_id,
                'lease_id' => $this->lease->id,
                'description' => 'Water recharge September',
                'issue_date' => '2026-10-15',
                'due_date' => '2026-10-22',
                'lines' => [['charge_type' => 'utility', 'description' => 'Water 12 units', 'amount' => '1450', 'vat' => false]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $this->assertSame('1450.00', Invoice::query()->where('description', 'Water recharge September')->sole()->total);
        $this->assertSame('1450.00', $this->account('4210')->balance());
    }

    public function test_finance_manager_voids_an_unpaid_invoice(): void
    {
        $invoice = $this->lease->invoices()->sole();
        $this->actingAs(User::factory()->withRole(UserRole::FinanceManager)->create());

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
            ->callAction('void', ['reason' => 'Billed in error']);

        $this->assertSame(InvoiceStatus::Void, $invoice->refresh()->status);
    }

    public function test_accountant_matches_an_unmatched_mpesa_payment(): void
    {
        $transaction = app(MpesaC2bService::class)->ingest([
            'TransID' => 'SJK0000001', 'TransAmount' => '30000', 'TransTime' => '20261014100000', 'BillRefNumber' => 'UNIT 4B',
        ]);
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());

        Livewire::test(ManageMpesaTransactions::class)
            ->callAction(TestAction::make('match')->table($transaction), ['tenant_id' => $this->lease->tenant_id])
            ->assertNotified('Payment receipted');

        $this->assertSame(MpesaTransactionStatus::Matched, $transaction->refresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $this->lease->invoices()->sole()->status);
    }

    public function test_kyc_documents_are_hidden_from_accountants(): void
    {
        $tenant = Tenant::query()->findOrFail($this->lease->tenant_id);

        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create())
            ->get("/admin/tenants/{$tenant->id}")
            ->assertOk()
            ->assertDontSee('KYC documents')
            ->assertDontSee($tenant->id_number);
    }

    public function test_property_manager_sees_kyc_documents_and_the_full_id_number(): void
    {
        $tenant = Tenant::query()->findOrFail($this->lease->tenant_id);

        $this->actingAs(User::factory()->withRole(UserRole::PropertyManager)->create())
            ->get("/admin/tenants/{$tenant->id}")
            ->assertOk()
            ->assertSee('KYC documents')
            ->assertSee($tenant->id_number);
    }
}
