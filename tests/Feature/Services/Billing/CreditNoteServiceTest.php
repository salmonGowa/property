<?php

namespace Tests\Feature\Services\Billing;

use App\Enums\CreditNoteType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\UnitType;
use App\Models\Lease;
use App\Services\Billing\BillingService;
use App\Services\Billing\CreditNoteService;
use App\Services\Billing\InvoiceService;
use App\Services\Collections\PaymentService;
use App\Services\Leasing\LeaseService;
use App\Services\Ledger\InvalidJournalEntry;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\SetsUpLedger;
use Tests\TestCase;

class CreditNoteServiceTest extends TestCase
{
    use LazilyRefreshDatabase, SetsUpLedger;

    private CreditNoteService $creditNotes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger();
        $this->creditNotes = app(CreditNoteService::class);
    }

    public function test_adjustment_reverses_income_and_vat_in_proportion_and_settles_the_invoice(): void
    {
        $lease = $this->activeLease(['rent_amount' => '100000.00', 'charge_vat' => true], UnitType::Office);
        $invoice = $lease->invoices()->sole();

        $creditNote = $this->creditNotes->issue($invoice, CreditNoteType::Adjustment, '58000', 'Rent-free fit-out fortnight');

        $this->assertSame('CN-000001', $creditNote->number);
        $this->assertSame('8000.00', $creditNote->vat_amount);
        $this->assertSame('50000.00', $this->account('4110')->balance());
        $this->assertSame('8000.00', $this->account('2300')->balance());
        $this->assertSame('58000.00', $this->account('1200')->balance());
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->refresh()->status);
    }

    public function test_write_off_is_charged_to_bad_debts_and_leaves_income_untouched(): void
    {
        $invoice = $this->activeLease()->invoices()->sole();

        $this->creditNotes->issue($invoice, CreditNoteType::WriteOff, '30000', 'Tenant absconded');

        $this->assertSame('30000.00', $this->account('5500')->balance());
        $this->assertSame('30000.00', $this->account('4100')->balance());
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
    }

    public function test_rejects_crediting_more_than_was_billed(): void
    {
        $invoice = $this->activeLease()->invoices()->sole();
        $this->creditNotes->issue($invoice, CreditNoteType::Adjustment, '20000', 'Part');

        $this->expectException(InvalidJournalEntry::class);
        $this->expectExceptionMessage('At most KES 10000.00');

        $this->creditNotes->issue($invoice, CreditNoteType::Adjustment, '10000.01', 'Too much');
    }

    public function test_credit_on_a_paid_invoice_becomes_credit_for_the_next_invoice(): void
    {
        $lease = $this->activeLease();
        $invoice = $lease->invoices()->sole();
        app(PaymentService::class)->record($lease->tenant, today(), PaymentMethod::Cash, '30000');

        $creditNote = $this->creditNotes->issue($invoice, CreditNoteType::Adjustment, '5000', 'Goodwill');
        $november = app(BillingService::class)->billLease($lease, today()->addMonth())[0];

        $this->assertSame('5000.00', $november->amount_paid);
        $this->assertSame('0.00', $creditNote->refresh()->unallocatedAmount());
    }

    public function test_invoice_with_a_credit_note_cannot_be_voided(): void
    {
        $invoice = $this->activeLease()->invoices()->sole();
        $this->creditNotes->issue($invoice, CreditNoteType::Adjustment, '100', 'Rounding');

        $this->expectException(InvalidJournalEntry::class);

        app(InvoiceService::class)->void($invoice, 'Try', null);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeLease(array $attributes = [], UnitType $type = UnitType::Apartment): Lease
    {
        $lease = $this->draftLease(['start_date' => '2026-10-01', ...$attributes], $type);
        app(LeaseService::class)->activate($lease);

        return $lease;
    }
}
