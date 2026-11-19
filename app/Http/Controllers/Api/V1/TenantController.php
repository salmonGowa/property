<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\TenantType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTenantRequest;
use App\Http\Requests\Api\V1\UpdateTenantRequest;
use App\Http\Resources\V1\TenantResource;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Arrears\ArrearsAging;
use App\Services\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    /**
     * Columns the list may be sorted by; prefix with "-" for descending.
     *
     * @var list<string>
     */
    private const array SORTABLE = ['name', 'account_number', 'balance', 'created_at'];

    public function __construct(private ArrearsAging $aging) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Tenant::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(TenantType::class)],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'owes' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in([...self::SORTABLE, ...array_map(fn (string $column): string => "-{$column}", self::SORTABLE)])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $sort = $filters['sort'] ?? 'name';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $status = $filters['status'] ?? 'active';

        $tenants = Tenant::query()
            ->withBalance()
            ->withCount('activeLeases')
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $this->search($query, $search))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($status !== 'all', fn (Builder $query) => $query->where('is_active', $status === 'active'))
            ->when($request->boolean('owes'), fn (Builder $query) => $query->whereRaw(
                '(select coalesce(sum(total), 0) from invoices where invoices.tenant_id = tenants.id and invoices.status != ?) > (select coalesce(sum(amount), 0) from payments where payments.tenant_id = tenants.id and payments.status = ?) + (select coalesce(sum(amount), 0) from credit_notes where credit_notes.tenant_id = tenants.id)',
                [InvoiceStatus::Void->value, PaymentStatus::Posted->value],
            ))
            ->when(
                ltrim($sort, '-') === 'balance',
                fn (Builder $query) => $query->orderByRaw("(COALESCE(billed_total, 0) - COALESCE(paid_total, 0) - COALESCE(credited_total, 0)) {$direction}"),
                fn (Builder $query) => $query->orderBy(ltrim($sort, '-'), $direction),
            )
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return TenantResource::collection($tenants);
    }

    public function store(StoreTenantRequest $request): TenantResource
    {
        $tenant = Tenant::query()->create($request->tenantAttributes());

        return $this->detail($tenant);
    }

    public function show(Tenant $tenant): TenantResource
    {
        Gate::authorize('view', $tenant);

        return $this->detail($tenant);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): TenantResource
    {
        $tenant->update($request->tenantAttributes());

        return $this->detail($tenant);
    }

    public function destroy(Tenant $tenant): Response
    {
        Gate::authorize('delete', $tenant);

        $tenant->delete();

        return response()->noContent();
    }

    /**
     * Matches name, account number, email or KRA PIN; a phone number matches however it is typed (0712..., +254712...).
     */
    private function search(Builder $query, string $search): Builder
    {
        $digits = preg_replace('/\D/', '', $search);
        $localDigits = preg_replace('/^(254|0)/', '', $digits);

        return $query->where(fn (Builder $query) => $query
            ->whereLike('name', "%{$search}%")
            ->orWhereLike('account_number', "%{$search}%")
            ->orWhereLike('email', "%{$search}%")
            ->orWhereLike('kra_pin', "%{$search}%")
            ->when(strlen($localDigits) >= 4, fn (Builder $query) => $query->orWhereLike('phone', "%{$localDigits}%")));
    }

    private function detail(Tenant $tenant): TenantResource
    {
        $wasRecentlyCreated = $tenant->wasRecentlyCreated;
        $tenant = Tenant::query()->withBalance()->withCount('activeLeases')->findOrFail($tenant->id);
        // Re-fetched for the balance aggregates; keep the flag that makes a create answer 201.
        $tenant->wasRecentlyCreated = $wasRecentlyCreated;
        $balance = $tenant->balance();
        $lastPayment = $tenant->payments()->where('status', PaymentStatus::Posted)->latest('received_on')->latest('id')->first();

        $resource = new TenantResource($tenant);
        $resource->summary = [
            'balance' => $balance,
            'overdue' => Invoice::query()->whereBelongsTo($tenant)->overdue()->get()
                ->reduce(fn (string $sum, Invoice $invoice): string => bcadd($sum, $invoice->balanceDue(), 2), '0.00'),
            'activeLeases' => $tenant->active_leases_count,
            'lastPayment' => $lastPayment ? ['amount' => (string) $lastPayment->amount, 'receivedOn' => $lastPayment->received_on->toDateString()] : null,
            'aging' => Money::isPositive($balance)
                ? collect($this->aging->forTenant($tenant))->map(fn (string $amount, string $key): array => ['key' => $key, 'label' => ArrearsAging::BUCKETS[$key], 'amount' => $amount])->values()->all()
                : null,
            'paybill' => config('services.mpesa.shortcode') ?: null,
        ];

        return $resource;
    }
}
