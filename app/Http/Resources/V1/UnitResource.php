<?php

namespace App\Http\Resources\V1;

use App\Http\Resources\Concerns\FormatsEnums;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Unit
 */
class UnitResource extends JsonResource
{
    use FormatsEnums;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'propertyId' => $this->property_id,
            'property' => $this->whenLoaded('property', fn (): array => [
                'id' => $this->property->id,
                'code' => $this->property->code,
                'name' => $this->property->name,
            ]),
            'code' => $this->code,
            'type' => self::enumData($this->type),
            'isCommercial' => $this->type->isCommercial(),
            'status' => self::enumData($this->status),
            'floor' => $this->floor,
            'bedrooms' => $this->bedrooms,
            'bathrooms' => $this->bathrooms,
            'areaSqft' => $this->area_sqft,
            'marketRent' => $this->market_rent,
            'serviceCharge' => $this->service_charge,
            'notes' => $this->notes,
            'activeLease' => $this->whenLoaded('activeLease', fn (): ?array => $this->activeLease ? [
                'id' => $this->activeLease->id,
                'reference' => $this->activeLease->reference,
                'endDate' => $this->activeLease->end_date?->toDateString(),
                'tenant' => $this->activeLease->relationLoaded('tenant') ? [
                    'id' => $this->activeLease->tenant->id,
                    'name' => $this->activeLease->tenant->name,
                ] : null,
            ] : null),
            'can' => [
                'update' => $request->user()->can('update', $this->resource),
                'delete' => $request->user()->can('delete', $this->resource),
            ],
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
