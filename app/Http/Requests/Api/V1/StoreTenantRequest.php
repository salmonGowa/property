<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\TenantType;
use App\Models\Tenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRequest extends FormRequest
{
    private const string KENYAN_MOBILE = '/^(\+?254|0)\s?[17]\d{2}\s?\d{3}\s?\d{3}$/';

    public function authorize(): bool
    {
        return $this->user()->can('create', Tenant::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('kra_pin'))) {
            $this->merge(['kra_pin' => strtoupper(trim($this->input('kra_pin'))) ?: null]);
        }
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        $isCompany = $this->input('type') === TenantType::Company->value;

        return [
            'type' => ['required', Rule::enum(TenantType::class)],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:'.self::KENYAN_MOBILE],
            'email' => ['nullable', 'email', 'max:255'],
            'kra_pin' => ['nullable', 'string', 'regex:/^[AP]\d{9}[A-Z]$/'],
            'id_number' => $isCompany ? ['exclude'] : ['nullable', 'string', 'max:20'],
            'registration_number' => $isCompany ? ['nullable', 'string', 'max:50'] : ['exclude'],
            'contact_person' => $isCompany ? ['nullable', 'string', 'max:255'] : ['exclude'],
            'contact_person_phone' => ['nullable', 'string', 'max:20'],
            'alternate_phone' => ['nullable', 'string', 'max:20'],
            'postal_address' => ['nullable', 'string', 'max:255'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a Kenyan mobile number, e.g. 0712 345 678 or +254 712 345 678.',
            'kra_pin.regex' => 'A KRA PIN is a letter, nine digits and a letter, e.g. A001234567Z.',
        ];
    }

    /**
     * Validated data ready to save: phone in +254 format, and identifiers that do not apply to the tenant type cleared.
     *
     * @return array<string, mixed>
     */
    public function tenantAttributes(): array
    {
        $isCompany = $this->input('type') === TenantType::Company->value;

        return [
            ...$this->validated(),
            'phone' => Tenant::normalisePhone($this->validated('phone')),
            ...($isCompany ? ['id_number' => null] : ['registration_number' => null, 'contact_person' => null]),
        ];
    }
}
