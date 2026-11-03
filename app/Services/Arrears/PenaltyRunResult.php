<?php

namespace App\Services\Arrears;

use App\Models\Invoice;

final class PenaltyRunResult
{
    /**
     * @param  list<Invoice>  $invoices  Penalty invoices raised
     * @param  array<string, string>  $failures  Error message keyed by overdue invoice number
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
