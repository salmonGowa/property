<?php

namespace App\Http\Requests\Api\V1;

class UpdateTenantRequest extends StoreTenantRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('tenant'));
    }
}
