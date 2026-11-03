<?php

namespace Database\Seeders;

use App\Enums\BillingFrequency;
use App\Enums\ChargeType;
use App\Enums\CovenantMetric;
use App\Enums\InterestRateType;
use App\Enums\KenyaCounty;
use App\Enums\LoanType;
use App\Enums\PaymentMethod;
use App\Enums\PenaltyCapType;
use App\Enums\PenaltyMethod;
use App\Enums\PenaltyRecurrence;
use App\Enums\PropertyType;
use App\Enums\RepaymentType;
use App\Enums\UnitType;
use App\Enums\UserRole;
use App\Enums\WithholdingTaxType;
use App\Enums\WorkOrderCategory;
use App\Enums\WorkOrderPriority;
use App\Models\Lender;
use App\Models\MaintenanceSchedule;
use App\Models\PenaltyRule;
use App\Models\Property;
use App\Models\PropertyValuation;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Collections\PaymentService;
use App\Services\Debt\LoanService;
use App\Services\Leasing\LeaseService;
use App\Services\Ledger\FiscalPeriodService;
use App\Services\Maintenance\WorkOrderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Sample portfolio for exploring the system: two properties, tenants, active leases,
 * invoices and payments. Everything it posts to the ledger is permanent, so only run
 * it against a development or demo database.
 */
class DemoDataSeeder extends Seeder
{
    public function run(LeaseService $leases, PaymentService $payments, FiscalPeriodService $fiscalPeriods, WorkOrderService $workOrders, LoanService $loans): void
    {
        $this->call(ChartOfAccountsSeeder::class);
        $fiscalPeriods->openYear((int) today()->subMonths(4)->year);
        $fiscalPeriods->openYear((int) today()->year);

        PenaltyRule::query()->firstOrCreate(['name' => 'Standard: 5% after 5 days'], [
            'method' => PenaltyMethod::Percentage,
            'rate' => '5',
            'grace_days' => 5,
            'recurrence' => PenaltyRecurrence::Monthly,
            'charge_types' => [ChargeType::Rent->value, ChargeType::ServiceCharge->value],
            'minimum_overdue' => '500',
            'cap_type' => PenaltyCapType::PercentOfInvoice,
            'cap_value' => '15',
            'effective_from' => today()->subMonths(4)->startOfMonth(),
            'is_default' => true,
        ]);

        $residential = Property::factory()->create([
            'code' => 'KIL-01', 'name' => 'Kilimani Heights', 'type' => PropertyType::Residential,
            'county' => KenyaCounty::Nairobi, 'town' => 'Kilimani', 'address' => 'Argwings Kodhek Road',
        ]);
        $commercial = Property::factory()->create([
            'code' => 'WLD-01', 'name' => 'Westlands Business Park', 'type' => PropertyType::Commercial,
            'county' => KenyaCounty::Nairobi, 'town' => 'Westlands', 'address' => 'Waiyaki Way',
        ]);

        $apartments = collect(['A-101', 'A-102', 'A-201', 'A-202', 'B-101', 'B-102'])->map(fn (string $code, int $i): Unit => Unit::factory()->create([
            'property_id' => $residential->id, 'code' => $code, 'type' => UnitType::Apartment,
            'bedrooms' => $i % 2 ? 3 : 2, 'market_rent' => $i % 2 ? '55000.00' : '42000.00', 'service_charge' => '4000.00',
        ]));
        $offices = collect(['G-01', 'G-02', '1-01', '1-02'])->map(fn (string $code): Unit => Unit::factory()->create([
            'property_id' => $commercial->id, 'code' => $code, 'type' => UnitType::Office,
            'bedrooms' => null, 'area_sqft' => '1500.00', 'market_rent' => '180000.00', 'service_charge' => '22500.00',
        ]));

        $start = today()->subMonths(3)->startOfMonth();

        foreach ($apartments->take(4) as $unit) {
            $tenant = Tenant::factory()->create();
            $lease = $leases->createDraft([
                'tenant_id' => $tenant->id, 'unit_id' => $unit->id, 'start_date' => $start, 'end_date' => $start->copy()->addYear()->subDay(),
                'billing_frequency' => BillingFrequency::Monthly, 'rent_amount' => $unit->market_rent, 'service_charge' => $unit->service_charge,
                'deposit_amount' => bcmul($unit->market_rent, '2', 2), 'charge_vat' => false, 'due_day' => 5,
            ]);
            $leases->activate($lease);
            $payments->record($tenant, $start->copy()->addDays(2), PaymentMethod::Mpesa, bcmul($unit->market_rent, '3', 2), strtoupper(fake()->bothify('S??#??#?#?')));
        }

        foreach ($offices->take(3) as $index => $unit) {
            $tenant = Tenant::factory()->company()->create();
            $lease = $leases->createDraft([
                'tenant_id' => $tenant->id, 'unit_id' => $unit->id, 'start_date' => $start, 'end_date' => $start->copy()->addYears(5)->subDay(),
                'billing_frequency' => $index === 0 ? BillingFrequency::Quarterly : BillingFrequency::Monthly,
                'rent_amount' => $unit->market_rent, 'service_charge' => $unit->service_charge, 'deposit_amount' => bcmul($unit->market_rent, '3', 2),
                'charge_vat' => true, 'due_day' => 5, 'escalation_rate' => '7.50', 'escalation_interval_months' => 12,
            ]);
            $leases->activate($lease);

            if ($index !== 2) {
                $payments->record($tenant, $start->copy()->addDays(10), PaymentMethod::BankTransfer, '500000.00', 'EFT-'.fake()->numerify('######'));
            }
        }

        $this->seedMaintenance($residential, $commercial, $apartments, $workOrders);
        $this->seedDebt($commercial, $loans);
    }

    /**
     * A variable-rate mortgage on the business park with covenants and a valuation.
     */
    private function seedDebt(Property $commercial, LoanService $loans): void
    {
        PropertyValuation::factory()->create(['property_id' => $commercial->id, 'market_value' => '450000000.00', 'valued_on' => today()->subMonths(5)]);

        $start = today()->subMonths(3)->startOfMonth();
        $loan = $loans->create([
            'lender_id' => Lender::factory()->create(['name' => 'Demo Bank Ltd'])->id,
            'name' => 'Westlands Business Park mortgage',
            'type' => LoanType::Mortgage,
            'facility_amount' => '180000000',
            'start_date' => $start,
            'first_payment_date' => $start->copy()->addMonth(),
            'maturity_date' => $start->copy()->addYears(10),
            'payment_frequency' => BillingFrequency::Monthly,
            'repayment_type' => RepaymentType::Annuity,
            'balloon_amount' => '40000000',
            'rate_type' => InterestRateType::Variable,
            'base_rate_name' => 'CBK Rate',
            'base_rate' => '9.75',
            'margin' => '3.5',
        ], [$commercial->id]);
        $loans->drawdown($loan, '180000000', $start, '1800000', reference: 'DD-0001');

        foreach ([[CovenantMetric::Dscr, '1.25'], [CovenantMetric::Ltv, '60'], [CovenantMetric::DebtYield, '10']] as [$metric, $threshold]) {
            $loan->covenants()->create(['metric' => $metric, 'threshold' => $threshold]);
        }

        while (($next = $loan->refresh()->nextInstalment) && $next->due_date->lt(today())) {
            $loans->payInstalment($next, $next->due_date, reference: 'SO-'.$next->due_date->format('Ym'));
        }
    }

    /**
     * Suppliers, a planned maintenance schedule and a couple of open work orders.
     *
     * @param  Collection<int, Unit>  $apartments
     */
    private function seedMaintenance(Property $residential, Property $commercial, Collection $apartments, WorkOrderService $workOrders): void
    {
        User::query()->whereNull('purchase_approval_limit')->where('role', UserRole::FinanceManager)->update(['purchase_approval_limit' => '500000']);
        User::query()->whereNull('purchase_approval_limit')->where('role', UserRole::PropertyManager)->update(['purchase_approval_limit' => '50000']);

        $plumber = Vendor::factory()->covering([WorkOrderCategory::Plumbing, WorkOrderCategory::General])
            ->withholding(WithholdingTaxType::ContractualFees)
            ->create(['name' => 'Maji Safi Plumbing Works', 'payment_method' => PaymentMethod::Mpesa, 'mpesa_number' => '0722000111']);
        Vendor::factory()->covering([WorkOrderCategory::Electrical])->vatRegistered()->withholding(WithholdingTaxType::ContractualFees)
            ->create(['name' => 'Umeme Electrical Contractors Ltd']);
        $lifts = Vendor::factory()->covering([WorkOrderCategory::Lifts])->vatRegistered()
            ->create(['name' => 'Highrise Lifts & Escalators Ltd', 'payment_terms_days' => 45]);

        MaintenanceSchedule::factory()->create([
            'property_id' => $commercial->id, 'title' => 'Quarterly lift servicing', 'category' => WorkOrderCategory::Lifts,
            'vendor_id' => $lifts->id, 'next_due_on' => today()->addDays(20), 'lead_days' => 7,
        ]);

        $unit = $apartments->first();
        $workOrders->log([
            'property_id' => $residential->id, 'unit_id' => $unit->id, 'tenant_id' => $unit->activeLease?->tenant_id,
            'title' => 'Kitchen sink leaking under the cabinet', 'category' => WorkOrderCategory::Plumbing,
            'priority' => WorkOrderPriority::High, 'vendor_id' => $plumber->id, 'estimated_cost' => '4500',
        ]);
        $workOrders->log([
            'property_id' => $residential->id, 'title' => 'Stairwell lights out on Block B', 'category' => WorkOrderCategory::Electrical,
            'priority' => WorkOrderPriority::Normal,
        ]);
    }
}
