<?php

namespace Tests\Feature\Filament\Resources;

use App\Enums\InvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\UserRole;
use App\Filament\Pages\PayablesAgingReport;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Resources\SupplierBills\Pages\CreateSupplierBill;
use App\Filament\Resources\SupplierBills\Pages\ListSupplierBills;
use App\Filament\Resources\SupplierBills\Pages\ViewSupplierBill;
use App\Filament\Resources\SupplierPayments\Pages\ListSupplierPayments;
use App\Filament\Resources\SupplierPayments\Pages\ViewSupplierPayment;
use App\Filament\Resources\Vendors\Pages\CreateVendor;
use App\Filament\Resources\Vendors\Pages\ListVendors;
use App\Filament\Resources\Vendors\Pages\ViewVendor;
use App\Models\PurchaseOrder;
use App\Models\SupplierBill;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Procurement\PurchaseOrderService;
use App\Services\Procurement\SupplierBillService;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SetsUpLedger;
use Tests\TestCase;

class ProcurementScreensTest extends TestCase
{
    use LazilyRefreshDatabase, SetsUpLedger;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger();
        $this->vendor = Vendor::factory()->create(['name' => 'Maji Safi Plumbing']);
    }

    public function test_procurement_pages_render_for_an_administrator(): void
    {
        $this->actingAs(User::factory()->create());
        $order = $this->submittedOrder();
        $bill = $this->bill();

        Livewire::test(ListVendors::class)->assertOk()->assertCanSeeTableRecords([$this->vendor]);
        Livewire::test(ViewVendor::class, ['record' => $this->vendor->id])->assertOk()->assertSee('Maji Safi Plumbing');
        Livewire::test(CreateVendor::class)->assertOk();
        Livewire::test(ListPurchaseOrders::class)->assertOk();
        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->id])->assertOk()->assertSee('Can be approved by');
        Livewire::test(CreatePurchaseOrder::class)->assertOk();
        Livewire::test(ListSupplierBills::class)->assertOk()->assertCanSeeTableRecords([$bill]);
        Livewire::test(ViewSupplierBill::class, ['record' => $bill->id])->assertOk();
        Livewire::test(ListSupplierPayments::class)->assertOk();
        Livewire::test(PayablesAgingReport::class)->assertOk()->assertSee('Maji Safi Plumbing');
    }

    public function test_accountant_adds_a_supplier(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());

        Livewire::test(CreateVendor::class)
            ->fillForm([
                'name' => 'Umeme Electrical Ltd',
                'kra_pin' => 'p051234567x',
                'categories' => ['electrical'],
                'is_vat_registered' => true,
                'withholding_tax_type' => 'contractual_fees',
                'payment_method' => 'bank_transfer',
                'bank_name' => 'Equity',
                'bank_account_number' => '0123456789',
                'payment_terms_days' => 30,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $vendor = Vendor::query()->where('name', 'Umeme Electrical Ltd')->sole();
        $this->assertSame('P051234567X', $vendor->kra_pin);
        $this->assertSame('SUP-0002', $vendor->number);
    }

    public function test_property_managers_cannot_change_supplier_bank_details(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::PropertyManager)->create());

        Livewire::test(ListVendors::class)->assertActionHidden('create');
    }

    public function test_property_manager_raises_and_submits_a_purchase_order(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::PropertyManager)->create());
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(CreatePurchaseOrder::class)
            ->fillForm([
                'vendor_id' => $this->vendor->id,
                'description' => 'Replace rising main',
                'order_date' => '2026-10-15',
                'lines' => [['account_id' => $this->account('5100')->id, 'description' => 'Pipes and labour', 'quantity' => '1', 'unit_price' => '38000', 'vat' => false]],
            ])
            ->callAction(TestAction::make('createAndSubmit')->schemaComponent('form-actions', schema: 'content'))
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $order = PurchaseOrder::query()->sole();
        $this->assertSame(PurchaseOrderStatus::PendingApproval, $order->status);
        $this->assertSame('38000.00', $order->total);
    }

    public function test_approve_is_only_offered_within_the_approvers_limit(): void
    {
        $order = $this->submittedOrder('60000');

        $this->actingAs(User::factory()->withRole(UserRole::FinanceManager)->create(['purchase_approval_limit' => '50000']));
        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->id])->assertActionHidden('approve');

        $this->actingAs(User::factory()->withRole(UserRole::FinanceManager)->create(['purchase_approval_limit' => '100000']));
        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->id])
            ->callAction('approve')
            ->assertNotified('Purchase order approved');

        $this->assertSame(PurchaseOrderStatus::Approved, $order->refresh()->status);
    }

    public function test_approver_returns_an_order_and_the_requester_edits_it(): void
    {
        $order = $this->submittedOrder();
        $this->actingAs(User::factory()->create());

        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->id])
            ->callAction('reject', ['reason' => 'Get a second quote'])
            ->assertNotified('Returned to the requester');

        $this->actingAs($order->requester);
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(EditPurchaseOrder::class, ['record' => $order->id])
            ->assertSee('Get a second quote')
            ->fillForm(['lines' => [['account_id' => $this->account('5100')->id, 'description' => 'Cheaper quote', 'quantity' => '1', 'unit_price' => '30000', 'vat' => false]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $this->assertSame('30000.00', $order->refresh()->total);
    }

    public function test_bill_is_prefilled_from_an_approved_order(): void
    {
        $order = $this->submittedOrder('40000');
        app(PurchaseOrderService::class)->approve($order, User::factory()->create());
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());
        $undoRepeaterFake = Repeater::fake();

        Livewire::withQueryParams(['purchase_order' => $order->id])
            ->test(CreateSupplierBill::class)
            ->assertSchemaStateSet(['vendor_id' => $this->vendor->id, 'purchase_order_id' => $order->id, 'description' => 'Plumbing works'])
            ->fillForm(['vendor_invoice_number' => 'MS-204', 'bill_date' => '2026-10-15', 'due_date' => '2026-11-14'])
            ->call('create')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $bill = SupplierBill::query()->sole();
        $this->assertSame('40000.00', $bill->subtotal);
        $this->assertSame(PurchaseOrderStatus::Closed, $order->refresh()->status);
    }

    public function test_finance_manager_pays_a_bill_and_prints_the_voucher(): void
    {
        $bill = $this->bill('12000');
        $this->actingAs(User::factory()->withRole(UserRole::FinanceManager)->create());

        Livewire::test(ViewSupplierBill::class, ['record' => $bill->id])
            ->callAction('paySupplier', ['method' => 'mpesa', 'amount' => '12000', 'paid_on' => '2026-10-15', 'reference' => 'sjk3h5t9qw'])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame(InvoiceStatus::Paid, $bill->refresh()->status);
        $payment = $this->vendor->payments()->sole();
        Livewire::test(ViewSupplierPayment::class, ['record' => $payment->id])->assertOk();
        $this->get(route('filament.admin.supplier-payments.print', $payment))->assertOk()->assertSee('SJK3H5T9QW');
    }

    public function test_accountants_cannot_pay_suppliers(): void
    {
        $bill = $this->bill();
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());

        Livewire::test(ViewSupplierBill::class, ['record' => $bill->id])->assertActionHidden('paySupplier');
    }

    public function test_approved_order_prints_for_the_supplier(): void
    {
        $order = $this->submittedOrder();
        app(PurchaseOrderService::class)->approve($order, User::factory()->create());
        $this->actingAs(User::factory()->create());

        $this->get(route('filament.admin.purchase-orders.print', $order))->assertOk()->assertSee('PURCHASE ORDER')->assertSee($order->number);
    }

    private function submittedOrder(string $amount = '40000'): PurchaseOrder
    {
        $orders = app(PurchaseOrderService::class);
        $order = $orders->create(['vendor_id' => $this->vendor->id, 'description' => 'Plumbing works', 'order_date' => '2026-10-15'], [
            ['account_id' => $this->account('5100')->id, 'description' => 'Replace riser', 'quantity' => '1', 'unit_price' => $amount, 'vat' => false],
        ], User::factory()->withRole(UserRole::PropertyManager)->create());
        $orders->submit($order);

        return $order->refresh();
    }

    private function bill(string $amount = '10000'): SupplierBill
    {
        return app(SupplierBillService::class)->record($this->vendor, 'MS-'.fake()->unique()->numerify('###'), '2026-10-15', null, 'Repairs', [
            ['account_id' => $this->account('5100')->id, 'description' => 'Repairs', 'amount' => $amount, 'vat' => false],
        ]);
    }
}
