<?php

namespace Tests\Feature\Filament;

use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every Phase 1 screen renders for an administrator against the demo portfolio.
 */
class PropertyManagementScreensTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-15');
        $this->seed(DemoDataSeeder::class);
        $this->actingAs(User::factory()->withRole(UserRole::Admin)->create());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function listAndCreatePages(): array
    {
        return [
            'dashboard' => ['/admin'],
            'properties' => ['/admin/properties'],
            'new property' => ['/admin/properties/create'],
            'units' => ['/admin/units'],
            'tenants' => ['/admin/tenants'],
            'new tenant' => ['/admin/tenants/create'],
            'leases' => ['/admin/leases'],
            'new lease' => ['/admin/leases/create'],
            'invoices' => ['/admin/invoices'],
            'new invoice' => ['/admin/invoices/create'],
            'payments' => ['/admin/payments'],
            'm-pesa inbox' => ['/admin/mpesa-transactions'],
            'penalty rules' => ['/admin/penalty-rules'],
            'credit notes' => ['/admin/credit-notes'],
            'repayment plans' => ['/admin/repayment-plans'],
            'arrears aging' => ['/admin/arrears-aging-report'],
        ];
    }

    #[DataProvider('listAndCreatePages')]
    public function test_page_renders(string $url): void
    {
        $this->get($url)->assertOk();
    }

    public function test_record_pages_render(): void
    {
        $tenant = Tenant::query()->whereHas('payments')->firstOrFail();
        $invoice = Invoice::query()->firstOrFail();

        $this->get('/admin/properties/'.Property::query()->value('id'))->assertOk()->assertSee('Occupancy');
        $this->get('/admin/units/'.Unit::query()->value('id'))->assertOk();
        $this->get("/admin/tenants/{$tenant->id}")->assertOk()->assertSee($tenant->account_number);
        $this->get('/admin/leases/'.Lease::query()->value('id'))->assertOk();
        $this->get("/admin/invoices/{$invoice->id}")->assertOk()->assertSee($invoice->number);
        $this->get('/admin/payments/'.Payment::query()->value('id'))->assertOk();
        $this->get("/admin/invoices/{$invoice->id}/print")->assertOk()->assertSee($invoice->tenant->account_number);
        $this->get("/admin/tenants/{$tenant->id}/statement")->assertOk()->assertSee('Closing balance');
    }

    public function test_demo_ledger_stays_balanced(): void
    {
        $this->get('/admin/trial-balance-report')->assertOk();

        $this->assertSame(
            (string) JournalLine::query()->sum('debit'),
            (string) JournalLine::query()->sum('credit'),
        );
    }
}
