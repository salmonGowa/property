<?php

namespace App\Services\Debt;

use App\Models\BadDebtProvision;
use App\Services\Arrears\ArrearsAging;
use App\Services\Ledger\InvalidJournalEntry;
use App\Services\Ledger\JournalLineData;
use App\Services\Ledger\LedgerAccounts;
use App\Services\Ledger\PostingService;
use App\Services\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Sets the allowance for doubtful debts from aged tenant arrears: a percentage of each aging
 * bucket. Only the change from the current allowance is posted (bad debts against the allowance).
 */
class BadDebtProvisionService
{
    /**
     * Percent provided against each bucket when no provision has been made before.
     *
     * @var array<string, string>
     */
    public const DEFAULT_RATES = [
        'current' => '0',
        'days_1_30' => '5',
        'days_31_60' => '25',
        'days_61_90' => '50',
        'days_over_90' => '100',
    ];

    public function __construct(
        private PostingService $posting,
        private LedgerAccounts $accounts,
        private ArrearsAging $aging,
    ) {}

    /**
     * The rates last used, or the defaults.
     *
     * @return array<string, string>
     */
    public function currentRates(): array
    {
        return BadDebtProvision::query()->latest('as_of')->latest('id')->value('rates') ?? self::DEFAULT_RATES;
    }

    /**
     * @param  array<string, int|string|float>  $rates
     * @return array{buckets: array<string, string>, provisions: array<string, string>, required: string, previous: string, adjustment: string}
     */
    public function calculate(array $rates): array
    {
        $buckets = $this->aging->totals($this->aging->byTenant())['buckets'];
        $provisions = [];

        foreach ($buckets as $key => $amount) {
            $provisions[$key] = Money::percentOf($amount, (string) ($rates[$key] ?? '0'));
        }

        $required = Money::sum($provisions);
        $previous = bcmul($this->accounts->get('doubtful_debts_allowance')->balance(), '-1', 2);

        return [
            'buckets' => $buckets,
            'provisions' => $provisions,
            'required' => $required,
            'previous' => $previous,
            'adjustment' => bcsub($required, $previous, 2),
        ];
    }

    /**
     * @param  array<string, int|string|float>  $rates
     *
     * @throws InvalidJournalEntry
     */
    public function post(array $rates, CarbonInterface|string $asOf): BadDebtProvision
    {
        $asOf = Carbon::parse($asOf);
        $rates = array_map(fn ($rate): string => bcadd((string) ($rate ?? '0'), '0', 2), array_merge(self::DEFAULT_RATES, array_intersect_key($rates, self::DEFAULT_RATES)));

        foreach ($rates as $rate) {
            if (bccomp($rate, '0', 2) < 0 || bccomp($rate, '100', 2) > 0) {
                throw new InvalidJournalEntry('Provision rates must be between 0% and 100%.');
            }
        }

        $result = $this->calculate($rates);
        $adjustment = $result['adjustment'];

        if (bccomp($adjustment, '0', 2) === 0) {
            throw new InvalidJournalEntry('The allowance already matches the arrears; nothing to post.');
        }

        return DB::transaction(function () use ($rates, $result, $adjustment, $asOf): BadDebtProvision {
            $provision = BadDebtProvision::query()->create([
                'as_of' => $asOf,
                'rates' => $rates,
                'buckets' => $result['buckets'],
                'required' => $result['required'],
                'previous' => $result['previous'],
                'adjustment' => $adjustment,
                'created_by' => Auth::id(),
            ]);

            $amount = ltrim($adjustment, '-');
            $raising = bccomp($adjustment, '0', 2) > 0;
            $badDebts = $this->accounts->get('bad_debts');
            $allowance = $this->accounts->get('doubtful_debts_allowance');

            $entry = $this->posting->post($asOf, ($raising ? 'Increase' : 'Release').' allowance for doubtful debts to KES '.number_format((float) $result['required'], 2), [
                $raising ? JournalLineData::debit($badDebts, $amount) : JournalLineData::debit($allowance, $amount),
                $raising ? JournalLineData::credit($allowance, $amount) : JournalLineData::credit($badDebts, $amount),
            ], 'Bad debt provision', $provision);

            $provision->update(['journal_entry_id' => $entry->id]);

            return $provision;
        });
    }
}
