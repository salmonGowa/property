<?php

namespace App\Services\Debt;

use App\Enums\CovenantMetric;
use App\Models\CovenantTest;
use App\Models\Loan;
use App\Models\LoanCovenant;
use App\Models\Property;
use App\Services\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Measures loan covenants. NOI is the trailing 12 months for the loan's security properties
 * (or the whole portfolio when none are charged); debt service is what the schedule says
 * falls due in the next 12 months; value is each property's latest valuation.
 */
class CovenantService
{
    private const SCALE = 8;

    public function __construct(private NetOperatingIncome $noi) {}

    /**
     * The figures every covenant on the loan is worked out from.
     *
     * @return array{noi: string, debt_service: string, outstanding: string, value: string|null, valued_properties: int, properties: int, from: string, to: string}
     */
    public function inputs(Loan $loan, CarbonInterface $on): array
    {
        $from = $on->copy()->subYear()->addDay();
        $propertyIds = $loan->properties()->pluck('properties.id')->all();
        $valuations = $loan->properties()->with(['valuations' => fn ($query) => $query->whereDate('valued_on', '<=', $on)])->get()
            ->map(fn (Property $property): ?string => $property->valuations->first()?->market_value)
            ->filter();

        return [
            'noi' => $this->noi->between($from, $on, $propertyIds ?: null)['noi'],
            'debt_service' => Money::round((string) $loan->instalments()->whereNull('loan_repayment_id')->whereDate('due_date', '>', $on)->whereDate('due_date', '<=', $on->copy()->addYear())->sum('total')),
            'outstanding' => $loan->outstandingPrincipal(),
            'value' => $valuations->isEmpty() ? null : Money::sum($valuations),
            'valued_properties' => $valuations->count(),
            'properties' => count($propertyIds),
            'from' => $from->toDateString(),
            'to' => $on->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $inputs  Reuse figures already worked out for the loan
     * @return array{actual: string|null, passed: bool|null, inputs: array<string, mixed>}
     */
    public function measure(LoanCovenant $covenant, CarbonInterface $on, ?array $inputs = null): array
    {
        $inputs ??= $this->inputs($covenant->loan, $on);
        $percent = fn (string $part, string $whole): string => bcmul(bcdiv($part, $whole, self::SCALE), '100', 3);

        $actual = match ($covenant->metric) {
            CovenantMetric::Dscr => Money::isPositive($inputs['debt_service']) ? bcdiv($inputs['noi'], $inputs['debt_service'], 3) : null,
            CovenantMetric::Ltv => $inputs['value'] !== null && Money::isPositive($inputs['value']) ? $percent($inputs['outstanding'], $inputs['value']) : null,
            CovenantMetric::DebtYield => Money::isPositive($inputs['outstanding']) ? $percent($inputs['noi'], $inputs['outstanding']) : null,
        };

        $passed = $actual === null ? null : ($covenant->metric->isMinimum()
            ? bccomp($actual, (string) $covenant->threshold, 3) >= 0
            : bccomp($actual, (string) $covenant->threshold, 3) <= 0);

        return ['actual' => $actual, 'passed' => $passed, 'inputs' => $inputs];
    }

    /**
     * Measure and record every active covenant on active loans. Re-testing the same day replaces the result.
     *
     * @return Collection<int, CovenantTest>
     */
    public function testAll(CarbonInterface|string $on): Collection
    {
        $on = Carbon::parse($on)->startOfDay();

        return Loan::query()->active()->with(['covenants' => fn ($query) => $query->where('is_active', true)])->get()
            ->flatMap(function (Loan $loan) use ($on): Collection {
                $inputs = $this->inputs($loan, $on);

                return $loan->covenants->map(fn (LoanCovenant $covenant): CovenantTest => $this->record($covenant, $on, $inputs));
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>|null  $inputs
     */
    public function record(LoanCovenant $covenant, CarbonInterface $on, ?array $inputs = null): CovenantTest
    {
        $result = $this->measure($covenant, $on, $inputs);

        return $covenant->tests()->updateOrCreate(['tested_on' => $on->toDateString()], [
            'actual' => $result['actual'],
            'threshold' => $covenant->threshold,
            'passed' => $result['passed'],
            'inputs' => $result['inputs'],
        ]);
    }
}
