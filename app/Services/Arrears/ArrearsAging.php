<?php

namespace App\Services\Arrears;

use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * Groups what tenants owe by how long it has been overdue, as at today.
 */
class ArrearsAging
{
    /**
     * Bucket keys and labels, in display order.
     */
    public const BUCKETS = [
        'current' => 'Not yet due',
        'days_1_30' => '1-30 days',
        'days_31_60' => '31-60 days',
        'days_61_90' => '61-90 days',
        'days_over_90' => '90+ days',
    ];

    /**
     * One row per tenant with an outstanding balance, largest overdue first.
     *
     * @return Collection<int, array{tenant: Tenant, buckets: array<string, string>, total: string, overdue: string}>
     */
    public function byTenant(?int $propertyId = null): Collection
    {
        return Invoice::query()
            ->outstanding()
            ->with('tenant')
            ->when($propertyId, fn ($query) => $query->whereHas('lease.unit', fn ($units) => $units->where('property_id', $propertyId)))
            ->get()
            ->groupBy('tenant_id')
            ->map(function (Collection $invoices): array {
                $buckets = $this->emptyBuckets();

                foreach ($invoices as $invoice) {
                    $key = $this->bucketFor($invoice);
                    $buckets[$key] = bcadd($buckets[$key], $invoice->balanceDue(), 2);
                }

                $total = array_reduce($buckets, fn (string $sum, string $amount): string => bcadd($sum, $amount, 2), '0.00');

                return [
                    'tenant' => $invoices->first()->tenant,
                    'buckets' => $buckets,
                    'total' => $total,
                    'overdue' => bcsub($total, $buckets['current'], 2),
                ];
            })
            ->sortByDesc(fn (array $row): float => (float) $row['overdue'])
            ->values();
    }

    /**
     * @return array<string, string>
     */
    public function forTenant(Tenant $tenant): array
    {
        $buckets = $this->emptyBuckets();

        foreach ($tenant->invoices()->outstanding()->get() as $invoice) {
            $key = $this->bucketFor($invoice);
            $buckets[$key] = bcadd($buckets[$key], $invoice->balanceDue(), 2);
        }

        return $buckets;
    }

    /**
     * @param  Collection<int, array{buckets: array<string, string>, total: string, overdue: string}>  $rows
     * @return array{buckets: array<string, string>, total: string, overdue: string}
     */
    public function totals(Collection $rows): array
    {
        $buckets = $this->emptyBuckets();

        foreach ($rows as $row) {
            foreach ($row['buckets'] as $key => $amount) {
                $buckets[$key] = bcadd($buckets[$key], $amount, 2);
            }
        }

        $total = array_reduce($buckets, fn (string $sum, string $amount): string => bcadd($sum, $amount, 2), '0.00');

        return ['buckets' => $buckets, 'total' => $total, 'overdue' => bcsub($total, $buckets['current'], 2)];
    }

    private function bucketFor(Invoice $invoice): string
    {
        $daysOverdue = (int) $invoice->due_date->startOfDay()->diffInDays(today(), false);

        return match (true) {
            $daysOverdue <= 0 => 'current',
            $daysOverdue <= 30 => 'days_1_30',
            $daysOverdue <= 60 => 'days_31_60',
            $daysOverdue <= 90 => 'days_61_90',
            default => 'days_over_90',
        };
    }

    /**
     * @return array<string, string>
     */
    private function emptyBuckets(): array
    {
        return array_map(fn (): string => '0.00', self::BUCKETS);
    }
}
