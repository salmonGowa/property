<?php

namespace App\Http\Requests\Api\V1;

class UpdatePropertyRequest extends StorePropertyRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('property'));
    }
}
