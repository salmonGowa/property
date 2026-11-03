<?php

namespace App\Services\Leasing;

use Carbon\CarbonImmutable;

final readonly class BillingPeriod
{
    /**
     * @param  CarbonImmutable  $start  First day of the full billing period
     * @param  CarbonImmutable  $end  Last day of the full billing period
     * @param  CarbonImmutable  $billedFrom  First day the tenant is charged for
     * @param  CarbonImmutable  $billedTo  Last day the tenant is charged for
     */
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public CarbonImmutable $billedFrom,
        public CarbonImmutable $billedTo,
    ) {}

    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    public function billedDays(): int
    {
        return (int) $this->billedFrom->diffInDays($this->billedTo) + 1;
    }

    public function isPartial(): bool
    {
        return $this->billedDays() < $this->days();
    }

    public function label(): string
    {
        return $this->billedFrom->isSameMonth($this->billedTo)
            ? $this->billedFrom->format('M Y')
            : $this->billedFrom->format('M Y').' - '.$this->billedTo->format('M Y');
    }
}
