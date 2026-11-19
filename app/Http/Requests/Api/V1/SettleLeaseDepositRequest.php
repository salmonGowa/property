<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PaymentMethod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettleLeaseDepositRequest extends FormRequest
{
    /**
     * Ways a deposit balance can be paid back to the tenant.
     *
     * @var list<PaymentMethod>
     */
    public const array REFUND_METHODS = [PaymentMethod::Mpesa, PaymentMethod::BankTransfer, PaymentMethod::Cheque, PaymentMethod::Cash];

    public function authorize(): bool
    {
        return $this->user()->can('settleDeposit', $this->route('lease'));
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date', 'before_or_equal:today'],
            'deductions' => ['sometimes', 'array', 'max:20'],
            'deductions.*.description' => ['required', 'string', 'max:255'],
            'deductions.*.amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999'],
            'refund_method' => ['nullable', Rule::enum(PaymentMethod::class)->only(self::REFUND_METHODS)],
            'refund_reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.before_or_equal' => 'The settlement date cannot be in the future.',
            'deductions.*.description.required' => 'Describe the charge.',
            'deductions.*.amount.min' => 'Enter an amount above zero.',
        ];
    }
}
