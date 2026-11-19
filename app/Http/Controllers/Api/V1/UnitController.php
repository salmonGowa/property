<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UnitStatus;
use App\Enums\UnitType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreUnitRequest;
use App\Http\Requests\Api\V1\UpdateUnitRequest;
use App\Http\Resources\V1\UnitResource;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    /**
     * @var list<string>
     */
    private const array SORTABLE = ['code', 'market_rent', 'status'];

    public function index(Request $request, Property $property): AnonymousResourceCollection
    {
        Gate::authorize('view', $property);
        Gate::authorize('viewAny', Unit::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(UnitStatus::class)],
            'type' => ['nullable', Rule::enum(UnitType::class)],
            'sort' => ['nullable', Rule::in([...self::SORTABLE, ...array_map(fn (string $column): string => "-{$column}", self::SORTABLE)])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $sort = $filters['sort'] ?? 'code';

        $units = $property->units()
            ->with('activeLease.tenant:id,name')
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                ->whereLike('code', "%{$search}%")
                ->orWhereHas('activeLease.tenant', fn (Builder $tenant) => $tenant->whereLike('name', "%{$search}%"))))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return UnitResource::collection($units);
    }

    public function store(StoreUnitRequest $request, Property $property): UnitResource
    {
        $unit = $property->units()->create($request->validated());

        return new UnitResource($unit->load(['property', 'activeLease.tenant:id,name']));
    }

    public function show(Unit $unit): UnitResource
    {
        Gate::authorize('view', $unit);

        return new UnitResource($unit->load(['property', 'activeLease.tenant:id,name']));
    }

    public function update(UpdateUnitRequest $request, Unit $unit): UnitResource
    {
        $unit->update($request->validated());

        return new UnitResource($unit->load(['property', 'activeLease.tenant:id,name']));
    }

    public function destroy(Unit $unit): Response
    {
        Gate::authorize('delete', $unit);

        $unit->delete();

        return response()->noContent();
    }
}
