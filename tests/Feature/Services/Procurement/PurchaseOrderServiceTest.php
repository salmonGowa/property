<?php

namespace Tests\Feature\Services\Procurement;

use App\Enums\PurchaseOrderStatus;
use App\Enums\UserRole;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Procurement\ProcurementActionRefused;
use App\Services\Procurement\PurchaseOrderService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\SetsUpLedger;
use Tests\TestCase;

class PurchaseOrderServiceTest extends TestCase
{
    use LazilyRefreshDatabase, SetsUpLedger;

    private PurchaseOrderService $orders;

    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger();
        $this->orders = app(PurchaseOrderService::class);
        $this->requester = User::factory()->withRole(UserRole::PropertyManager)->create();
    }

    public function test_drafts_an_order_with_line_totals_and_vat(): void
    {
        $order = $this->draft(Vendor::factory()->vatRegistered()->create(), vat: true);

        $this->assertSame('PO-000001', $order->number);
        $this->assertSame(PurchaseOrderStatus::Draft, $order->status);
        $this->assertSame('50000.00', $order->subtotal);
        $this->assertSame('8000.00', $order->vat_amount);
        $this->assertSame('58000.00', $order->total);
        $this->assertSame('12500.00', $order->lines()->sole()->unit_price);
    }

    public function test_refuses_vat_from_a_supplier_that_is_not_vat_registered(): void
    {
        $this->expectException(ProcurementActionRefused::class);
        $this->expectExceptionMessage('is not VAT registered');

        $this->draft(Vendor::factory()->create(), vat: true);
    }

    public function test_approver_within_their_limit_approves(): void
    {
        $order = $this->draft();
        $this->orders->submit($order);
        $approver = User::factory()->withRole(UserRole::FinanceManager)->create(['purchase_approval_limit' => '50000']);

        $this->orders->approve($order, $approver);

        $this->assertSame(PurchaseOrderStatus::Approved, $order->refresh()->status);
        $this->assertTrue($order->approver->is($approver));
    }

    public function test_approval_above_the_limit_is_refused(): void
    {
        $order = $this->draft();
        $this->orders->submit($order);
        $approver = User::factory()->withRole(UserRole::FinanceManager)->create(['purchase_approval_limit' => '49999.99']);

        $this->assertSame('KES 50,000.00 is above your approval limit of KES 49,999.99.', $this->orders->approvalBlocker($order, $approver));
        $this->expectException(ProcurementActionRefused::class);

        $this->orders->approve($order, $approver);
    }

    public function test_users_without_a_limit_cannot_approve_but_administrators_can(): void
    {
        $order = $this->draft();
        $this->orders->submit($order);

        $this->assertStringContainsString('no purchase approval limit', $this->orders->approvalBlocker($order, User::factory()->withRole(UserRole::FinanceManager)->create()));
        $this->assertNull($this->orders->approvalBlocker($order, User::factory()->withRole(UserRole::Admin)->create()));
    }

    public function test_requester_cannot_approve_their_own_order(): void
    {
        $this->requester->update(['purchase_approval_limit' => '1000000']);
        $order = $this->draft();
        $this->orders->submit($order);

        $this->assertSame('You raised this order, so someone else must approve it.', $this->orders->approvalBlocker($order, $this->requester));
    }

    public function test_rejection_returns_the_order_to_draft_with_the_reason(): void
    {
        $order = $this->draft();
        $this->orders->submit($order);
        $approver = User::factory()->withRole(UserRole::Admin)->create();

        $this->orders->reject($order, $approver, 'Get a second quote');

        $order->refresh();
        $this->assertSame(PurchaseOrderStatus::Draft, $order->status);
        $this->assertSame('Get a second quote', $order->rejection_reason);

        $this->orders->update($order, $this->details($order->vendor), [$this->line(quantity: '2')]);
        $this->orders->submit($order);
        $this->assertSame('25000.00', $order->refresh()->total);
        $this->assertNull($order->rejection_reason);
    }

    public function test_only_drafts_can_be_edited(): void
    {
        $order = $this->draft();
        $this->orders->submit($order);

        $this->expectException(ProcurementActionRefused::class);

        $this->orders->update($order, $this->details($order->vendor), [$this->line()]);
    }

    private function draft(?Vendor $vendor = null, bool $vat = false): PurchaseOrder
    {
        $vendor ??= Vendor::factory()->create();

        return $this->orders->create($this->details($vendor), [$this->line(vat: $vat)], $this->requester);
    }

    /**
     * @return array{vendor_id: int, description: string, order_date: string}
     */
    private function details(Vendor $vendor): array
    {
        return ['vendor_id' => $vendor->id, 'description' => 'Repaint stairwell', 'order_date' => '2026-10-15'];
    }

    /**
     * @return array{account_id: int, description: string, quantity: string, unit_price: string, vat: bool}
     */
    private function line(string $quantity = '4', bool $vat = false): array
    {
        return ['account_id' => $this->account('5100')->id, 'description' => 'Painting labour (days)', 'quantity' => $quantity, 'unit_price' => '12500', 'vat' => $vat];
    }
}
