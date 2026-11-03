<?php

namespace App\Services\Arrears;

use App\Models\Invoice;
use App\Models\PenaltyRule;
use Carbon\CarbonImmutable;

/**
 * Penalties due on one overdue invoice under one rule, not yet charged.
 */
final readonly class PenaltyAssessment
{
    /**
     * @param  list<array{occurrence: int, date: CarbonImmutable, amount: string, days: int}>  $occurrences
     */
    public function __construct(
        public Invoice $invoice,
        public PenaltyRule $rule,
        public string $basis,
        public array $occurrences,
    ) {}

    public function total(): string
    {
        return array_reduce($this->occurrences, fn (string $sum, array $occurrence): string => bcadd($sum, $occurrence['amount'], 2), '0.00');
    }
}
