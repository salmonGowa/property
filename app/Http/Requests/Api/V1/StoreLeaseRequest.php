<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\BillingFrequency;
use App\Enums\UnitStatus;
use App\Models\Lease;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaseRequest extends FormRequest
{
    /**
     * Amounts that default to zero when left blank.
     *
     * @var list<string>
     */
    private const array ZERO_WHEN_BLANK = ['service_charge', 'deposit_amount', 'escalation_rate'];

    public function authorize(): bool
    {
        return $this->user()->can('create', Lease::class);
    }

    protected function prepareForValidation(): void
    {
        foreach (self::ZERO_WHEN_BLANK as $field) {
            if ($this->has($field) && blank($this->input($field))) {
                $this->merge([$field => '0']);
            }
        }

        // An exempt lease has no penalty rule of its own.
        if ($this->boolean('penalties_exempt')) {
            $this->merge(['penalty_rule_id' => null]);
        }
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        $currentUnitId = $this->route('lease')?->unit_id;

        return [
            'tenant_id' => ['required', 'integer', Rule::exists('tenants', 'id')->where('is_active', true)],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where(fn (Builder $units) => $units
                ->whereIn('status', [UnitStatus::Vacant->value, UnitStatus::Reserved->value])
                ->when($currentUnitId, fn (Builder $units) => $units->orWhere('id', $currentUnitId)))],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'billing_frequency' => ['required', Rule::enum(BillingFrequency::class)],
            'due_day' => ['required', 'integer', 'between:1,28'],
            'rent_amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'service_charge' => ['sometimes', 'numeric', 'min:0', 'max:999999999999'],
            'deposit_amount' => ['sometimes', 'numeric', 'min:0', 'max:999999999999'],
            'charge_vat' => ['sometimes', 'boolean'],
            'escalation_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'escalation_interval_months' => ['required', 'integer', Rule::in([12, 24, 36])],
            'penalty_rule_id' => ['nullable', 'integer', Rule::exists('penalty_rules', 'id')->where('is_active', true)],
            'penalties_exempt' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tenant_id.exists' => 'Choose an active tenant.',
            'unit_id.exists' => 'This unit is not available: only vacant and reserved units can be let.',
            'end_date.after' => 'The end date must be after the start date.',
            'due_day.between' => 'Rent must fall due between the 1st and the 28th.',
            'penalty_rule_id.exists' => 'Choose an active penalty rule.',
        ];
    }
}
