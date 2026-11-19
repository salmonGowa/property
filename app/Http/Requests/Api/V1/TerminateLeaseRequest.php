<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TerminateLeaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('terminate', $this->route('lease'));
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'terminated_on' => ['required', 'date', 'after_or_equal:'.$this->route('lease')->start_date->toDateString()],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'terminated_on.after_or_equal' => 'The termination date cannot be before the lease started.',
        ];
    }
}
