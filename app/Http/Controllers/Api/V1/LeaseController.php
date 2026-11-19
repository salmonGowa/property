<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BillingFrequency;
use App\Enums\LeaseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLeaseRequest;
use App\Http\Requests\Api\V1\UpdateLeaseRequest;
use App\Http\Resources\V1\LeaseResource;
use App\Models\Lease;
use App\Services\Leasing\LeaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class LeaseController extends Controller
{
    /**
     * Columns the list may be sorted by; prefix with "-" for descending.
     *
     * @var list<string>
     */
    private const array SORTABLE = ['reference', 'start_date', 'end_date', 'rent_amount'];

    /**
     * List tabs, matching the classic back office.
     *
     * @var list<string>
     */
    private const array STATUSES = ['active', 'expiring', 'draft', 'terminated', 'all'];

    public function __construct(private LeaseService $leases) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Lease::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
            'property_id' => ['nullable', 'integer'],
            'tenant_id' => ['nullable', 'integer'],
            'unit_id' => ['nullable', 'integer'],
            'billing_frequency' => ['nullable', Rule::enum(BillingFrequency::class)],
            'sort' => ['nullable', Rule::in([...self::SORTABLE, ...array_map(fn (string $column): string => "-{$column}", self::SORTABLE)])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $filters['status'] ?? 'active';
        $sort = $filters['sort'] ?? ($status === 'expiring' ? 'end_date' : '-start_date');

        $scoped = fn (): Builder => Lease::query()
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                ->whereLike('reference', "%{$search}%")
                ->orWhereHas('tenant', fn (Builder $tenant) => $tenant->whereLike('name', "%{$search}%"))
                ->orWhereHas('unit', fn (Builder $unit) => $unit->whereLike('code', "%{$search}%"))))
            ->when($filters['property_id'] ?? null, fn (Builder $query, int|string $propertyId) => $query->whereHas('unit', fn (Builder $unit) => $unit->where('property_id', $propertyId)))
            ->when($filters['tenant_id'] ?? null, fn (Builder $query, int|string $tenantId) => $query->where('tenant_id', $tenantId))
            ->when($filters['unit_id'] ?? null, fn (Builder $query, int|string $unitId) => $query->where('unit_id', $unitId))
            ->when($filters['billing_frequency'] ?? null, fn (Builder $query, string $frequency) => $query->where('billing_frequency', $frequency));

        $leases = $this->applyStatus($scoped(), $status)
            ->with(['tenant:id,name,account_number,phone', 'unit:id,property_id,code,type', 'unit.property:id,code,name'])
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('id', 'desc')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return LeaseResource::collection($leases)->additional([
            'counts' => collect(['active', 'expiring', 'draft'])
                ->mapWithKeys(fn (string $tab): array => [$tab => $this->applyStatus($scoped(), $tab)->count()])
                ->all(),
        ]);
    }

    public function store(StoreLeaseRequest $request): LeaseResource
    {
        $lease = $this->leases->createDraft($request->validated());

        return LeaseResource::detailed($lease);
    }

    public function show(Lease $lease): LeaseResource
    {
        Gate::authorize('view', $lease);

        return LeaseResource::detailed($lease);
    }

    public function update(UpdateLeaseRequest $request, Lease $lease): LeaseResource
    {
        $lease->update($request->validated());

        return LeaseResource::detailed($lease);
    }

    public function destroy(Lease $lease): Response
    {
        Gate::authorize('delete', $lease);

        $lease->delete();

        return response()->noContent();
    }

    private function applyStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            'expiring' => $query->expiringWithin(LeaseResource::EXPIRY_WARNING_DAYS),
            'all' => $query,
            default => $query->where('status', LeaseStatus::from($status)),
        };
    }
}
