<?php

namespace App\Services\Leasing;

final readonly class DepositSettlement
{
    /**
     * @param  string  $held  Deposit held before settlement
     * @param  string  $applied  Used to clear what the tenant owed
     * @param  string  $refunded  Paid back to the tenant now
     * @param  string  $stillHeld  Refundable but not yet paid out
     */
    public function __construct(
        public string $held,
        public string $applied,
        public string $refunded,
        public string $stillHeld,
    ) {}
}
