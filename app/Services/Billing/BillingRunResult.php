<?php

namespace App\Services\Billing;

use App\Models\Invoice;

final class BillingRunResult
{
    /**
     * @param  list<Invoice>  $invoices
     * @param  array<string, string>  $failures  Error message keyed by lease reference
     */
    public function __construct(
        public array $invoices = [],
        public array $failures = [],
    ) {}

    public function total(): string
    {
        return array_reduce($this->invoices, fn (string $sum, Invoice $invoice): string => bcadd($sum, (string) $invoice->total, 2), '0.00');
    }
}
