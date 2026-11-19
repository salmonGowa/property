<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UnitStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Concerns\FormatsEnums;
use App\Models\Lease;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Units a lease can be drafted on: vacant or reserved ones, plus the unit a draft being edited already uses.
 */
class LettableUnitController extends Controller
{
    use FormatsEnums;

    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('create', Lease::class);

        $request->validate(['include' => ['nullable', 'integer']]);

        $units = Unit::query()
            ->with('property:id,code,name')
            ->where(fn (Builder $query) => $query
                ->whereIn('status', [UnitStatus::Vacant, UnitStatus::Reserved])
                ->when($request->integer('include'), fn (Builder $query, int $unitId) => $query->orWhere('id', $unitId)))
            ->get()
            ->sortBy([fn (Unit $a, Unit $b): int => strcmp($a->property->name, $b->property->name), fn (Unit $a, Unit $b): int => strnatcmp($a->code, $b->code)])
            ->map(fn (Unit $unit): array => [
                'id' => $unit->id,
                'code' => $unit->code,
                'fullCode' => $unit->fullCode(),
                'type' => self::enumData($unit->type),
                'isCommercial' => $unit->type->isCommercial(),
                'status' => self::enumData($unit->status),
                'marketRent' => $unit->market_rent,
                'serviceCharge' => $unit->service_charge,
                'property' => ['id' => $unit->property->id, 'code' => $unit->property->code, 'name' => $unit->property->name],
            ])
            ->values();

        return response()->json(['data' => $units]);
    }
}
