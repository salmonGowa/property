<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeLeasePenaltyRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('changePenaltyRule', $this->route('lease'));
    }

    protected function prepareForValidation(): void
    {
        if ($this->boolean('penalties_exempt')) {
            $this->merge(['penalty_rule_id' => null]);
        }
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'penalties_exempt' => ['required', 'boolean'],
            'penalty_rule_id' => ['nullable', 'integer', Rule::exists('penalty_rules', 'id')->where('is_active', true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'penalty_rule_id.exists' => 'Choose an active penalty rule.',
        ];
    }
}
