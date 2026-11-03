<?php

namespace Tests\Feature\Services\Collections;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Lease;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Collections\PaymentService;
use App\Services\Leasing\LeaseService;
use App\Services\Ledger\InvalidJournalEntry;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\SetsUpLedger;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use LazilyRefreshDatabase, SetsUpLedger;

    private PaymentService $payments;

    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger();
        $this->payments = app(PaymentService::class);
        $this->lease = $this->draftLease(['start_date' => '2026-09-01']);
        app(LeaseService::class)->activate($this->lease);
    }

    public function test_payment_settles_oldest_invoice_first_and_part_pays_the_next(): void
    {
        [$september, $october] = $this->lease->invoices()->orderBy('due_date')->get();

        $this->payments->record($this->lease->tenant, today(), PaymentMethod::Mpesa, '45000', 'SJK3H5T9QW');

        $this->assertSame(InvoiceStatus::Paid, $september->refresh()->status);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $october->refresh()->status);
        $this->assertSame('15000.00', $october->balanceDue());
        $this->assertSame('45000.00', $this->account('1120')->balance());
        $this->assertSame('15000.00', $this->account('1200')->balance());
    }

    public function test_overpayment_is_held_as_credit_and_applied_to_the_next_invoice(): void
    {
        $payment = $this->payments->record($this->lease->tenant, today(), PaymentMethod::BankTransfer, '70000', 'EFT-001');

        $this->assertSame('10000.00', $payment->unallocatedAmount());
        $this->assertSame('-10000.00', $this->lease->tenant->balance());

        $november = app(BillingService::class)->billLease($this->lease, today()->addMonth())[0];

        $this->assertSame(InvoiceStatus::PartiallyPaid, $november->status);
        $this->assertSame('10000.00', $november->amount_paid);
        $this->assertSame('0.00', $payment->refresh()->unallocatedAmount());
    }

    public function test_reversing_a_bounced_cheque_reopens_the_invoices_it_paid(): void
    {
        $payment = $this->payments->record($this->lease->tenant, today(), PaymentMethod::Cheque, '30000', '000123');

        $this->payments->reverse($payment, 'Cheque returned unpaid', User::factory()->create());

        $this->assertSame(PaymentStatus::Reversed, $payment->refresh()->status);
        $this->assertSame(0, $payment->allocations()->count());
        $this->assertSame([InvoiceStatus::Unpaid, InvoiceStatus::Unpaid], $this->lease->invoices()->pluck('status')->all());
        $this->assertSame('0.00', $this->account('1100')->balance());
    }

    public function test_rejects_an_mpesa_code_that_was_already_receipted(): void
    {
        $this->payments->record($this->lease->tenant, today(), PaymentMethod::Mpesa, '1000', 'SJK3H5T9QW');

        $this->expectException(InvalidJournalEntry::class);
        $this->expectExceptionMessage('already been recorded');

        $this->payments->record($this->lease->tenant, today(), PaymentMethod::Mpesa, '1000', 'sjk3h5t9qw');
    }

    public function test_withholding_tax_certificate_is_debited_to_tax_credits(): void
    {
        $this->payments->record($this->lease->tenant, today(), PaymentMethod::WithholdingTax, '3000', 'WHT-CERT-77');

        $this->assertSame('3000.00', $this->account('1320')->balance());
    }
}
