<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UnitStatus;
use App\Models\Unit;
use Illuminate\Contracts\Validation\ValidationRule;

class UpdateUnitRequest extends StoreUnitRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('unit'));
    }

    /**
     * An occupied unit's status belongs to its lease and cannot be changed here.
     *
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        if ($this->unit()->status === UnitStatus::Occupied) {
            $rules['status'] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'status.prohibited' => 'Occupied by an active lease. Terminate the lease to change this.',
        ];
    }

    protected function propertyId(): int
    {
        return $this->unit()->property_id;
    }

    private function unit(): Unit
    {
        return $this->route('unit');
    }
}
