<?php

namespace Tests\Feature\Services\Billing;

use App\Enums\ChargeType;
use App\Enums\InvoiceStatus;
use App\Enums\UnitType;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\InvoiceService;
use App\Services\Leasing\LeaseService;
use App\Services\Ledger\FiscalPeriodService;
use App\Services\Ledger\InvalidJournalEntry;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SetsUpLedger;
use Tests\TestCase;

class BillingServiceTest extends TestCase
{
    use LazilyRefreshDatabase, SetsUpLedger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger();
    }

    public function test_running_billing_twice_never_bills_a_period_twice(): void
    {
        $lease = $this->draftLease(['start_date' => '2026-10-01']);
        app(LeaseService::class)->activate($lease);

        $result = app(BillingService::class)->run(Carbon::parse('2026-11-01'));
        $again = app(BillingService::class)->run(Carbon::parse('2026-11-01'));

        $this->assertCount(1, $result->invoices);
        $this->assertCount(0, $again->invoices);
        $this->assertSame(2, $lease->invoices()->count());
    }

    public function test_residential_rent_posts_receivable_and_rent_income_without_vat(): void
    {
        $lease = $this->draftLease(['start_date' => '2026-10-01', 'service_charge' => '3000.00']);

        app(LeaseService::class)->activate($lease);

        $invoice = $lease->invoices()->sole();
        $this->assertSame('5', (string) $invoice->due_date->day);
        $this->assertSame('33000.00', $invoice->total);
        $this->assertSame('33000.00', $this->account('1200')->balance());
        $this->assertSame('30000.00', $this->account('4100')->balance());
        $this->assertSame('3000.00', $this->account('4200')->balance());
        $this->assertSame('0.00', $this->account('2300')->balance());
    }

    public function test_commercial_lease_charges_vat_and_credits_commercial_rent(): void
    {
        $lease = $this->draftLease(['start_date' => '2026-10-01', 'rent_amount' => '100000.00', 'charge_vat' => true], UnitType::Office);

        app(LeaseService::class)->activate($lease);

        $invoice = $lease->invoices()->sole();
        $this->assertSame('16000.00', $invoice->vat_amount);
        $this->assertSame('116000.00', $invoice->total);
        $this->assertSame('100000.00', $this->account('4110')->balance());
        $this->assertSame('16000.00', $this->account('2300')->balance());
    }

    public function test_voiding_reverses_the_posting_and_allows_the_period_to_be_billed_again(): void
    {
        $lease = $this->draftLease(['start_date' => '2026-10-01']);
        app(LeaseService::class)->activate($lease);
        $invoice = $lease->invoices()->sole();

        app(InvoiceService::class)->void($invoice, 'Wrong rent amount', User::factory()->create());
        $rebilled = app(BillingService::class)->billLease($lease->refresh(), today());

        $this->assertSame(InvoiceStatus::Void, $invoice->refresh()->status);
        $this->assertCount(1, $rebilled);
        $this->assertSame('30000.00', $this->account('1200')->balance());
    }

    public function test_billing_run_reports_leases_it_could_not_bill(): void
    {
        $lease = $this->draftLease(['start_date' => '2026-11-01']);
        app(LeaseService::class)->activate($lease);
        app(FiscalPeriodService::class)->close(FiscalPeriod::query()->where('starts_on', '2026-11-01')->sole(), User::factory()->create());

        $result = app(BillingService::class)->run(Carbon::parse('2026-11-01'));

        $this->assertSame([], $result->invoices);
        $this->assertStringContainsString('closed', $result->failures[$lease->reference]);
    }

    public function test_manual_invoice_rejects_due_date_before_issue_date(): void
    {
        $lease = $this->draftLease();

        $this->expectException(InvalidJournalEntry::class);

        app(InvoiceService::class)->issue($lease->tenant, '2026-10-10', '2026-10-01', 'Water', [
            ['charge_type' => ChargeType::Utility, 'description' => 'Water', 'amount' => '1200', 'vat' => false],
        ]);
    }

    public function test_billing_command_reports_invoices_issued(): void
    {
        $lease = $this->draftLease(['start_date' => '2026-10-01']);
        app(LeaseService::class)->activate($lease);

        $this->artisan('billing:run', ['--date' => '2026-12-01'])
            ->expectsOutputToContain('2 invoice(s) issued totalling KES 60,000.00')
            ->assertSuccessful();

        $this->assertSame(3, Invoice::query()->count());
    }
}
