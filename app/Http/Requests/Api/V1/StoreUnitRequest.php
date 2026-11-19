<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UnitStatus;
use App\Enums\UnitType;
use App\Models\Unit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Unit::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }

        // A blank service charge means none; the column is NOT NULL with a zero default.
        if ($this->has('service_charge') && blank($this->input('service_charge'))) {
            $this->merge(['service_charge' => '0']);
        }
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('units', 'code')->where('property_id', $this->propertyId())->ignore($this->route('unit'))],
            'type' => ['required', Rule::enum(UnitType::class)],
            'status' => ['sometimes', Rule::enum(UnitStatus::class)->except([UnitStatus::Occupied])],
            'floor' => ['nullable', 'string', 'max:20'],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'bathrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'area_sqft' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'market_rent' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'service_charge' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'This property already has a unit with that number.',
            'status.enum' => 'Units become occupied only when a lease is activated.',
        ];
    }

    protected function propertyId(): int
    {
        return $this->route('property')->id;
    }
}
