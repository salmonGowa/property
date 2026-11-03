<?php

namespace Tests\Feature\Services\Leasing;

use App\Enums\CreditNoteType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\CreditNote;
use App\Models\Lease;
use App\Services\Collections\PaymentService;
use App\Services\Leasing\DepositService;
use App\Services\Leasing\LeaseActionRefused;
use App\Services\Leasing\LeaseService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\SetsUpLedger;
use Tests\TestCase;

class DepositServiceTest extends TestCase
{
    use LazilyRefreshDatabase, SetsUpLedger;

    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger();
        $this->lease = $this->draftLease(['start_date' => '2026-10-01', 'deposit_amount' => '60000.00']);
        app(LeaseService::class)->activate($this->lease);
    }

    public function test_only_deposit_actually_paid_counts_as_held(): void
    {
        // Rent due 5 Oct is settled before the deposit billed on 15 Oct, leaving 40,000 for the deposit.
        app(PaymentService::class)->record($this->lease->tenant, today(), PaymentMethod::Cash, '70000');

        $this->assertSame('40000.00', app(DepositService::class)->heldFor($this->lease));
    }

    public function test_move_out_deducts_damages_from_the_deposit_and_refunds_the_rest(): void
    {
        app(PaymentService::class)->record($this->lease->tenant, today(), PaymentMethod::BankTransfer, '90000', 'EFT-1');
        app(LeaseService::class)->terminate($this->lease, '2026-10-31', 'Tenant gave notice');

        $settlement = app(DepositService::class)->settle(
            $this->lease->refresh(),
            '2026-10-31',
            [['description' => 'Repaint living room', 'amount' => '8000']],
            PaymentMethod::Mpesa,
            'REFUND001',
        );

        $this->assertSame('60000.00', $settlement->held);
        $this->assertSame('8000.00', $settlement->applied);
        $this->assertSame('52000.00', $settlement->refunded);
        $this->assertSame('0.00', $this->lease->tenant->refresh()->balance());
        $this->assertSame('0.00', $this->account('2200')->balance());
        $this->assertSame('-52000.00', $this->account('1120')->balance());
        $this->assertSame('0.00', app(DepositService::class)->heldFor($this->lease));
    }

    public function test_unpaid_deposit_is_cancelled_with_a_credit_note_at_move_out(): void
    {
        app(LeaseService::class)->terminate($this->lease, '2026-10-31', 'Never paid deposit');

        app(DepositService::class)->settle($this->lease->refresh(), '2026-10-31');

        $creditNote = CreditNote::query()->where('reason', 'Unpaid deposit cancelled at move-out')->sole();
        $this->assertSame(CreditNoteType::Adjustment, $creditNote->type);
        $this->assertSame(InvoiceStatus::Paid, $creditNote->invoice->refresh()->status);
    }

    public function test_refuses_to_settle_a_lease_that_is_still_active(): void
    {
        $this->expectException(LeaseActionRefused::class);

        app(DepositService::class)->settle($this->lease, today());
    }
}
