<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\WithholdingTaxType;
use App\Enums\WorkOrderCategory;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' '.fake()->randomElement(['Contractors', 'Enterprises', 'Services Ltd', 'Hardware']),
            'kra_pin' => strtoupper(fake()->unique()->bothify('P#########?')),
            'categories' => [WorkOrderCategory::General->value],
            'contact_person' => fake()->name(),
            'phone' => '07'.fake()->numerify('########'),
            'email' => fake()->companyEmail(),
            'is_vat_registered' => false,
            'withholding_tax_type' => WithholdingTaxType::None,
            'payment_method' => PaymentMethod::BankTransfer,
            'bank_name' => 'KCB',
            'bank_branch' => 'Moi Avenue',
            'bank_account_number' => fake()->numerify('##########'),
            'payment_terms_days' => 30,
            'is_active' => true,
        ];
    }

    public function vatRegistered(): static
    {
        return $this->state(fn (array $attributes) => ['is_vat_registered' => true]);
    }

    public function withholding(WithholdingTaxType $type = WithholdingTaxType::ContractualFees): static
    {
        return $this->state(fn (array $attributes) => ['withholding_tax_type' => $type]);
    }

    /**
     * @param  list<WorkOrderCategory>  $categories
     */
    public function covering(array $categories): static
    {
        return $this->state(fn (array $attributes) => ['categories' => array_map(fn (WorkOrderCategory $category): string => $category->value, $categories)]);
    }
}
