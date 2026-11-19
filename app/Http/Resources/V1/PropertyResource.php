<?php

namespace App\Http\Resources\V1;

use App\Http\Resources\Concerns\FormatsEnums;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin Property
 */
class PropertyResource extends JsonResource
{
    use FormatsEnums;

    /**
     * Occupancy and rent-roll figures, set by the controller on the detail endpoint only.
     *
     * @var array{units: int, occupied: int, vacant: int, occupancyRate: int, monthlyRentRoll: string}|null
     */
    public ?array $stats = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => self::enumData($this->type),
            'county' => self::enumData($this->county),
            'town' => $this->town,
            'location' => trim("{$this->town}, {$this->county?->getLabel()}", ', '),
            'address' => $this->address,
            'lrNumber' => $this->lr_number,
            'totalAreaSqft' => $this->total_area_sqft,
            'yearBuilt' => $this->year_built,
            'description' => $this->description,
            'isActive' => $this->is_active,
            'photoUrl' => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null,
            'managerId' => $this->manager_id,
            'manager' => $this->whenLoaded('manager', fn (): ?array => $this->manager ? ['id' => $this->manager->id, 'name' => $this->manager->name] : null),
            'penaltyRuleId' => $this->penalty_rule_id,
            'penaltyRule' => $this->whenLoaded('penaltyRule', fn (): ?array => $this->penaltyRule ? ['id' => $this->penaltyRule->id, 'name' => $this->penaltyRule->name] : null),
            'unitsCount' => $this->whenCounted('units'),
            'occupancyRate' => $this->when(isset($this->units_count), fn (): int => $this->occupancyRate()),
            'stats' => $this->when($this->stats !== null, fn (): ?array => $this->stats),
            'can' => [
                'update' => $request->user()->can('update', $this->resource),
                'delete' => $request->user()->can('delete', $this->resource),
            ],
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
