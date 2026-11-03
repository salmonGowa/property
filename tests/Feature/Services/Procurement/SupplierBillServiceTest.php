<?php

namespace Tests\Feature\Services\Procurement;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\UserRole;
use App\Enums\WithholdingTaxType;
use App\Models\PurchaseOrder;
use App\Models\SupplierBill;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Ledger\InvalidJournalEntry;
use App\Services\Procurement\ProcurementActionRefused;
use App\Services\Procurement\PurchaseOrderService;
use App\Services\Procurement\SupplierBillService;
use App\Services\Procurement\SupplierPaymentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\SetsUpLedger;
use Tests\TestCase;

class SupplierBillServiceTest extends TestCase
{
    use LazilyRefreshDatabase, SetsUpLedger;

    private SupplierBillService $bills;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger();
        $this->bills = app(SupplierBillService::class);
    }

    public function test_posts_expense_vat_input_payable_and_withholding_tax(): void
    {
        $vendor = Vendor::factory()->vatRegistered()->withholding(WithholdingTaxType::ContractualFees)->create();

        $bill = $this->bills->record($vendor, 'inv-0042', '2026-10-15', null, 'Roof repairs', [
            ['account_id' => $this->account('5100')->id, 'description' => 'Labour and materials', 'amount' => '100000', 'vat' => true],
        ]);

        $this->assertSame('BILL-000001', $bill->number);
        $this->assertSame('INV-0042', $bill->vendor_invoice_number);
        $this->assertSame('116000.00', $bill->total);
        $this->assertSame('3000.00', $bill->withholding_tax_amount);
        $this->assertSame('113000.00', $bill->payable_amount);
        $this->assertSame('2026-11-14', $bill->due_date->toDateString());
        $this->assertSame('100000.00', $this->account('5100')->balance());
        $this->assertSame('16000.00', $this->account('1310')->balance());
        $this->assertSame('113000.00', $this->account('2100')->balance());
        $this->assertSame('3000.00', $this->account('2330')->balance());
    }

    public function test_refuses_the_same_supplier_invoice_twice(): void
    {
        $vendor = Vendor::factory()->create();
        $this->simpleBill($vendor, 'A-1');

        $this->expectException(ProcurementActionRefused::class);
        $this->expectExceptionMessage('has already been entered');

        $this->simpleBill($vendor, 'a-1');
    }

    public function test_billing_against_an_order_is_capped_and_closes_it_when_fully_billed(): void
    {
        $order = $this->approvedOrder('40000');

        $this->simpleBill($order->vendor, 'A-1', '25000', $order);
        $this->assertSame(PurchaseOrderStatus::Approved, $order->refresh()->status);
        $this->assertSame('15000.00', $order->remainingSubtotal());

        try {
            $this->simpleBill($order->vendor, 'A-2', '15000.01', $order);
            $this->fail('Billing above the order was allowed.');
        } catch (ProcurementActionRefused $exception) {
            $this->assertStringContainsString('more than the KES 15000.00 left', $exception->getMessage());
        }

        $last = $this->simpleBill($order->vendor, 'A-2', '15000', $order);
        $this->assertSame(PurchaseOrderStatus::Closed, $order->refresh()->status);

        $this->bills->void($last, 'Entered twice', null);
        $this->assertSame(PurchaseOrderStatus::Approved, $order->refresh()->status);
    }

    public function test_orders_must_be_approved_before_billing(): void
    {
        $vendor = Vendor::factory()->create();
        $order = app(PurchaseOrderService::class)->create(['vendor_id' => $vendor->id, 'description' => 'Pump', 'order_date' => '2026-10-15'], [
            ['account_id' => $this->account('5100')->id, 'description' => 'Pump', 'quantity' => '1', 'unit_price' => '9000', 'vat' => false],
        ]);

        $this->expectException(ProcurementActionRefused::class);
        $this->expectExceptionMessage('Only approved orders can be billed');

        $this->simpleBill($vendor, 'X', '9000', $order);
    }

    public function test_payment_settles_bills_earliest_due_first_and_can_be_reversed(): void
    {
        $vendor = Vendor::factory()->create();
        $later = $this->simpleBill($vendor, 'B', '30000', dueDate: '2026-11-30');
        $earlier = $this->simpleBill($vendor, 'A', '20000', dueDate: '2026-11-10');

        $payment = app(SupplierPaymentService::class)->pay($vendor, '2026-10-20', PaymentMethod::BankTransfer, '35000', [$later, $earlier], 'EFT-77');

        $this->assertSame('PV-000001', $payment->number);
        $this->assertSame(InvoiceStatus::Paid, $earlier->refresh()->status);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $later->refresh()->status);
        $this->assertSame('15000.00', $vendor->balance());
        $this->assertSame('15000.00', $this->account('2100')->balance());
        $this->assertSame('-35000.00', $this->account('1100')->balance());

        app(SupplierPaymentService::class)->reverse($payment, 'Transfer recalled', User::factory()->withRole(UserRole::FinanceManager)->create());

        $this->assertSame(PaymentStatus::Reversed, $payment->refresh()->status);
        $this->assertSame(InvoiceStatus::Unpaid, $earlier->refresh()->status);
        $this->assertSame('50000.00', $vendor->balance());
    }

    public function test_cannot_pay_more_than_the_chosen_bills(): void
    {
        $vendor = Vendor::factory()->create();
        $bill = $this->simpleBill($vendor, 'A', '20000');

        $this->expectException(ProcurementActionRefused::class);

        app(SupplierPaymentService::class)->pay($vendor, '2026-10-20', PaymentMethod::Cash, '20000.01', [$bill]);
    }

    public function test_paid_bills_cannot_be_voided(): void
    {
        $vendor = Vendor::factory()->create();
        $bill = $this->simpleBill($vendor, 'A', '20000');
        app(SupplierPaymentService::class)->pay($vendor, '2026-10-20', PaymentMethod::Cash, '5000', [$bill]);

        $this->expectException(InvalidJournalEntry::class);

        $this->bills->void($bill->refresh(), 'Wrong supplier', null);
    }

    private function approvedOrder(string $amount): PurchaseOrder
    {
        $orders = app(PurchaseOrderService::class);
        $order = $orders->create(['vendor_id' => Vendor::factory()->create()->id, 'description' => 'Plumbing works', 'order_date' => '2026-10-15'], [
            ['account_id' => $this->account('5100')->id, 'description' => 'Replace riser', 'quantity' => '1', 'unit_price' => $amount, 'vat' => false],
        ]);
        $orders->submit($order);
        $orders->approve($order, User::factory()->withRole(UserRole::Admin)->create());

        return $order->refresh();
    }

    private function simpleBill(Vendor $vendor, string $invoiceNumber, string $amount = '10000', ?PurchaseOrder $order = null, ?string $dueDate = null): SupplierBill
    {
        return $this->bills->record($vendor, $invoiceNumber, '2026-10-15', $dueDate, 'Works', [
            ['account_id' => $this->account('5100')->id, 'description' => 'Works', 'amount' => $amount, 'vat' => false],
        ], $order);
    }
}
