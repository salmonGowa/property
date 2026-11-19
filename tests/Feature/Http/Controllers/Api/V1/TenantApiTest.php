<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Collections\PaymentService;
use App\Services\Leasing\LeaseService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\SetsUpLedger;
use Tests\TestCase;

class TenantApiTest extends TestCase
{
    use LazilyRefreshDatabase, SetsUpLedger;

    public function test_guests_cannot_list_tenants(): void
    {
        $this->getJson('/api/v1/tenants')->assertUnauthorized();
    }

    public function test_roles_without_property_access_are_refused(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::WarehouseManager)->create())
            ->getJson('/api/v1/tenants')
            ->assertForbidden();
    }

    public function test_lists_active_tenants_with_balance_and_active_leases(): void
    {
        $this->setUpLedger();
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());

        $lease = $this->draftLease(['start_date' => '2026-10-01', 'rent_amount' => '30000.00']);
        $lease->tenant->update(['name' => 'Achieng Otieno']);
        app(LeaseService::class)->activate($lease);
        Tenant::factory()->create(['is_active' => false]);

        $this->getJson('/api/v1/tenants')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Achieng Otieno')
            ->assertJsonPath('data.0.balance', '30000.00')
            ->assertJsonPath('data.0.activeLeasesCount', 1);
    }

    public function test_finds_a_tenant_by_phone_however_the_number_is_typed(): void
    {
        $this->actingAs(User::factory()->create());

        Tenant::factory()->create(['name' => 'Wanjiru Kamau', 'phone' => '+254712345678']);
        Tenant::factory()->create(['phone' => '+254798765432']);

        $this->getJson('/api/v1/tenants?search=0712 345')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Wanjiru Kamau');
        $this->getJson('/api/v1/tenants?search=%2B254712345678')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Wanjiru Kamau');
    }

    public function test_owes_filter_and_balance_sort_put_the_largest_debtor_first(): void
    {
        $this->setUpLedger();
        $this->actingAs(User::factory()->create());

        foreach (['Small Debtor' => '10000.00', 'Large Debtor' => '50000.00'] as $name => $rent) {
            $lease = $this->draftLease(['start_date' => '2026-10-01', 'rent_amount' => $rent]);
            $lease->tenant->update(['name' => $name]);
            app(LeaseService::class)->activate($lease);
        }
        Tenant::factory()->create(['name' => 'Paid Up']);

        $this->getJson('/api/v1/tenants?owes=1&sort=-balance')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Large Debtor')
            ->assertJsonPath('data.1.name', 'Small Debtor');
    }

    public function test_property_manager_registers_an_individual_tenant(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::PropertyManager)->create());

        $response = $this->postJson('/api/v1/tenants', [
            'type' => 'individual',
            'name' => 'Wanjiru Kamau',
            'phone' => '0712 345 678',
            'kra_pin' => 'a001234567z',
            'id_number' => '12345678',
            'registration_number' => 'PVT-IGNORED',
        ])->assertCreated();

        $tenant = Tenant::query()->sole();
        $this->assertSame('+254712345678', $tenant->phone);
        $this->assertSame('A001234567Z', $tenant->kra_pin);
        $this->assertSame('12345678', $tenant->id_number);
        $this->assertNull($tenant->registration_number);
        $this->assertNotEmpty($tenant->account_number);
        $response->assertJsonPath('data.accountNumber', $tenant->account_number)
            ->assertJsonPath('data.summary.balance', '0.00');
    }

    public function test_switching_a_tenant_to_a_company_clears_the_personal_id_number(): void
    {
        $this->actingAs(User::factory()->create());
        $tenant = Tenant::factory()->create(['id_number' => '12345678']);

        $this->putJson("/api/v1/tenants/{$tenant->id}", [
            'type' => 'company',
            'name' => 'Savannah Traders Limited',
            'phone' => '+254 712 345 678',
            'registration_number' => 'PVT-AB1234',
            'contact_person' => 'Otieno Ouma',
            'id_number' => '12345678',
        ])->assertOk()->assertJsonPath('data.type.value', 'company');

        $tenant->refresh();
        $this->assertNull($tenant->id_number);
        $this->assertSame('PVT-AB1234', $tenant->registration_number);
    }

    public function test_rejects_numbers_that_are_not_kenyan_mobiles_and_malformed_kra_pins(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/v1/tenants', ['type' => 'individual', 'name' => 'Test', 'phone' => '020 123 4567', 'kra_pin' => 'A12'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'phone' => 'Enter a Kenyan mobile number',
                'kra_pin' => 'A KRA PIN is a letter, nine digits and a letter',
            ]);
    }

    public function test_read_only_roles_cannot_register_tenants(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());

        $this->postJson('/api/v1/tenants', ['type' => 'individual', 'name' => 'Test', 'phone' => '0712345678'])->assertForbidden();
    }

    public function test_detail_summarises_the_account(): void
    {
        $this->setUpLedger();
        $this->actingAs(User::factory()->create());
        config(['services.mpesa.shortcode' => '600100']);

        $lease = $this->draftLease(['start_date' => '2026-09-01', 'rent_amount' => '30000.00']);
        app(LeaseService::class)->activate($lease);
        app(PaymentService::class)->record($lease->tenant, '2026-10-10', PaymentMethod::Mpesa, '30000', 'QAB12CD34E');

        $this->getJson("/api/v1/tenants/{$lease->tenant_id}")
            ->assertOk()
            ->assertJsonPath('data.summary.balance', '30000.00')
            ->assertJsonPath('data.summary.overdue', '30000.00')
            ->assertJsonPath('data.summary.activeLeases', 1)
            ->assertJsonPath('data.summary.lastPayment', ['amount' => '30000.00', 'receivedOn' => '2026-10-10'])
            ->assertJsonPath('data.summary.aging.1', ['key' => 'days_1_30', 'label' => '1-30 days', 'amount' => '30000.00'])
            ->assertJsonPath('data.summary.paybill', '600100');
    }

    public function test_id_numbers_are_masked_for_roles_that_may_not_see_kyc_data(): void
    {
        $tenant = Tenant::factory()->create(['id_number' => '12345678']);

        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create())
            ->getJson("/api/v1/tenants/{$tenant->id}")
            ->assertJsonPath('data.idNumber', '•••••678')
            ->assertJsonPath('data.idNumberMasked', true);

        $this->actingAs(User::factory()->withRole(UserRole::PropertyManager)->create())
            ->getJson("/api/v1/tenants/{$tenant->id}")
            ->assertJsonPath('data.idNumber', '12345678')
            ->assertJsonPath('data.idNumberMasked', false);
    }

    public function test_only_tenants_without_history_can_be_deleted(): void
    {
        $this->actingAs(User::factory()->create());
        $unused = Tenant::factory()->create();
        $withLease = Lease::factory()->create()->tenant;

        $this->deleteJson("/api/v1/tenants/{$withLease->id}")->assertForbidden();
        $this->deleteJson("/api/v1/tenants/{$unused->id}")->assertNoContent();

        $this->assertModelMissing($unused);
        $this->assertModelExists($withLease);
    }
}
