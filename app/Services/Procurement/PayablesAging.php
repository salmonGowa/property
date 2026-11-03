<?php

namespace App\Services\Procurement;

use App\Models\SupplierBill;
use App\Models\Vendor;
use App\Services\Arrears\ArrearsAging;
use Illuminate\Support\Collection;

/**
 * Groups what the company owes suppliers by how long it has been overdue, as at today.
 * Uses the same buckets as tenant arrears aging.
 */
class PayablesAging
{
    /**
     * One row per supplier with unpaid bills, largest overdue first.
     *
     * @return Collection<int, array{vendor: Vendor, buckets: array<string, string>, total: string, overdue: string}>
     */
    public function byVendor(): Collection
    {
        return SupplierBill::query()
            ->outstanding()
            ->with('vendor')
            ->get()
            ->groupBy('vendor_id')
            ->map(function (Collection $bills): array {
                $buckets = array_map(fn (): string => '0.00', ArrearsAging::BUCKETS);

                foreach ($bills as $bill) {
                    $key = $this->bucketFor($bill);
                    $buckets[$key] = bcadd($buckets[$key], $bill->balanceDue(), 2);
                }

                $total = array_reduce($buckets, fn (string $sum, string $amount): string => bcadd($sum, $amount, 2), '0.00');

                return [
                    'vendor' => $bills->first()->vendor,
                    'buckets' => $buckets,
                    'total' => $total,
                    'overdue' => bcsub($total, $buckets['current'], 2),
                ];
            })
            ->sortByDesc(fn (array $row): float => (float) $row['overdue'])
            ->values();
    }

    /**
     * @param  Collection<int, array{buckets: array<string, string>, total: string, overdue: string}>  $rows
     * @return array{buckets: array<string, string>, total: string, overdue: string}
     */
    public function totals(Collection $rows): array
    {
        return app(ArrearsAging::class)->totals($rows);
    }

    private function bucketFor(SupplierBill $bill): string
    {
        $daysOverdue = (int) $bill->due_date->startOfDay()->diffInDays(today(), false);

        return match (true) {
            $daysOverdue <= 0 => 'current',
            $daysOverdue <= 30 => 'days_1_30',
            $daysOverdue <= 60 => 'days_31_60',
            $daysOverdue <= 90 => 'days_61_90',
            default => 'days_over_90',
        };
    }
}
