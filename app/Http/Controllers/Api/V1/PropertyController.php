<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\KenyaCounty;
use App\Enums\PropertyType;
use App\Enums\UnitStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePropertyRequest;
use App\Http\Requests\Api\V1\UpdatePropertyRequest;
use App\Http\Resources\V1\PropertyResource;
use App\Models\Lease;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PropertyController extends Controller
{
    /**
     * Columns the list may be sorted by; prefix with "-" for descending.
     *
     * @var list<string>
     */
    private const array SORTABLE = ['name', 'code', 'units_count', 'created_at'];

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Property::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(PropertyType::class)],
            'county' => ['nullable', Rule::enum(KenyaCounty::class)],
            'manager_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'sort' => ['nullable', Rule::in([...self::SORTABLE, ...array_map(fn (string $column): string => "-{$column}", self::SORTABLE)])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $sort = $filters['sort'] ?? 'name';

        $properties = Property::query()
            ->with('manager:id,name')
            ->withCount(['units', 'units as occupied_units_count' => fn (Builder $units) => $units->where('status', UnitStatus::Occupied)])
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('code', "%{$search}%")
                ->orWhereLike('town', "%{$search}%")
                ->orWhereLike('lr_number', "%{$search}%")))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['county'] ?? null, fn (Builder $query, string $county) => $query->where('county', $county))
            ->when($filters['manager_id'] ?? null, fn (Builder $query, int|string $managerId) => $query->where('manager_id', $managerId))
            ->when(($filters['status'] ?? 'active') !== 'all', fn (Builder $query) => $query->where('is_active', ($filters['status'] ?? 'active') === 'active'))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return PropertyResource::collection($properties);
    }

    public function store(StorePropertyRequest $request): PropertyResource
    {
        $property = Property::query()->create($request->validated());

        return $this->detail($property);
    }

    public function show(Property $property): PropertyResource
    {
        Gate::authorize('view', $property);

        return $this->detail($property);
    }

    public function update(UpdatePropertyRequest $request, Property $property): PropertyResource
    {
        $property->update($request->validated());

        return $this->detail($property);
    }

    public function destroy(Property $property): Response
    {
        Gate::authorize('delete', $property);

        $photoPath = $property->photo_path;

        $property->delete();

        if ($photoPath !== null) {
            Storage::disk('public')->delete($photoPath);
        }

        return response()->noContent();
    }

    private function detail(Property $property): PropertyResource
    {
        $property->load(['manager:id,name', 'penaltyRule:id,name'])
            ->loadCount(['units', 'units as occupied_units_count' => fn (Builder $units) => $units->where('status', UnitStatus::Occupied)]);

        $resource = new PropertyResource($property);
        $resource->stats = [
            'units' => $property->units_count,
            'occupied' => $property->occupied_units_count,
            'vacant' => $property->units()->where('status', UnitStatus::Vacant)->count(),
            'occupancyRate' => $property->occupancyRate(),
            'monthlyRentRoll' => number_format((float) Lease::query()->active()->whereIn('unit_id', $property->units()->select('id'))->sum('rent_amount'), 2, '.', ''),
        ];

        return $resource;
    }
}
