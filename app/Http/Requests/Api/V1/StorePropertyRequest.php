<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\KenyaCounty;
use App\Enums\PropertyType;
use App\Enums\UserRole;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Property::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20', Rule::unique('properties', 'code')->ignore($this->route('property'))],
            'type' => ['required', Rule::enum(PropertyType::class)],
            'county' => ['required', Rule::enum(KenyaCounty::class)],
            'town' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'lr_number' => ['nullable', 'string', 'max:255'],
            'total_area_sqft' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'year_built' => ['nullable', 'integer', 'min:1900', 'max:'.((int) date('Y') + 5)],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)->whereIn('role', [UserRole::PropertyManager->value, UserRole::Admin->value])],
            'penalty_rule_id' => ['nullable', 'integer', Rule::exists('penalty_rules', 'id')->where('is_active', true)],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'manager_id.exists' => 'Choose an active property manager or administrator.',
        ];
    }
}
