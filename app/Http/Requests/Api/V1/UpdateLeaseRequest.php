<?php

namespace App\Http\Requests\Api\V1;

/**
 * Only drafts can be edited (see LeasePolicy); once active, the terms drive billing.
 */
class UpdateLeaseRequest extends StoreLeaseRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('lease'));
    }
}
