<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RenewLeaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('renew', $this->route('lease'));
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            // Activating the renewal ends the current lease the day before, so it must start after the current one did.
            'start_date' => ['required', 'date', 'after:'.$this->route('lease')->start_date->toDateString()],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'rent_amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start_date.after' => 'The renewal must start after the current lease started.',
            'end_date.after' => 'The end date must be after the start date.',
        ];
    }
}
