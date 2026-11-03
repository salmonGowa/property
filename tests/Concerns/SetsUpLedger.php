<?php

namespace Tests\Concerns;

use App\Enums\UnitType;
use App\Models\Account;
use App\Models\Lease;
use App\Models\Unit;
use App\Services\Ledger\FiscalPeriodService;
use Database\Seeders\ChartOfAccountsSeeder;

trait SetsUpLedger
{
    /**
     * Load the standard chart of accounts and open the 2026 fiscal year, with "today" fixed.
     */
    protected function setUpLedger(string $today = '2026-10-15'): void
    {
        $this->travelTo($today);
        $this->seed(ChartOfAccountsSeeder::class);
        app(FiscalPeriodService::class)->openYear(2026);
        app(FiscalPeriodService::class)->openYear(2027);
    }

    protected function account(string $code): Account
    {
        return Account::query()->where('code', $code)->sole();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function draftLease(array $attributes = [], UnitType $unitType = UnitType::Apartment): Lease
    {
        return Lease::factory()->create([
            'unit_id' => Unit::factory()->ofType($unitType),
            ...$attributes,
        ]);
    }
}
