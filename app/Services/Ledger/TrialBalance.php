<?php

namespace App\Services\Ledger;

use App\Models\Account;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class TrialBalance
{
    /**
     * Net debit or credit balance per account with postings up to the given date.
     *
     * @return Collection<int, array{account: Account, debit: string, credit: string}>
     */
    public function rows(CarbonInterface $asOf): Collection
    {
        $postedOnOrBefore = fn ($lines) => $lines->whereHas('entry', fn ($entry) => $entry->whereDate('entry_date', '<=', $asOf));

        return Account::query()
            ->whereHas('lines', $postedOnOrBefore)
            ->withSum(['lines as debit_total' => $postedOnOrBefore], 'debit')
            ->withSum(['lines as credit_total' => $postedOnOrBefore], 'credit')
            ->orderBy('code')
            ->get()
            ->map(function (Account $account): array {
                $net = bcsub((string) ($account->debit_total ?? '0'), (string) ($account->credit_total ?? '0'), 2);
                $isDebit = bccomp($net, '0', 2) >= 0;

                return [
                    'account' => $account,
                    'debit' => $isDebit ? $net : '0.00',
                    'credit' => $isDebit ? '0.00' : bcmul($net, '-1', 2),
                ];
            });
    }

    /**
     * @param  Collection<int, array{account: Account, debit: string, credit: string}>  $rows
     * @return array{debit: string, credit: string}
     */
    public function totals(Collection $rows): array
    {
        return [
            'debit' => $rows->reduce(fn (string $sum, array $row): string => bcadd($sum, $row['debit'], 2), '0.00'),
            'credit' => $rows->reduce(fn (string $sum, array $row): string => bcadd($sum, $row['credit'], 2), '0.00'),
        ];
    }
}
