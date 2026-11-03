<?php

namespace App\Services\Ledger;

use App\Models\Account;

/**
 * One debit or credit to be posted. Amounts are decimal strings with at most two
 * decimal places; floats are rejected so rounding can never creep into the ledger.
 */
final readonly class JournalLineData
{
    public string $debit;

    public string $credit;

    public function __construct(
        public int $accountId,
        int|string $debit = '0',
        int|string $credit = '0',
        public ?string $description = null,
    ) {
        $this->debit = self::normalise($debit);
        $this->credit = self::normalise($credit);
    }

    public static function debit(Account|int $account, int|string $amount, ?string $description = null): self
    {
        return new self(self::accountId($account), debit: $amount, description: $description);
    }

    public static function credit(Account|int $account, int|string $amount, ?string $description = null): self
    {
        return new self(self::accountId($account), credit: $amount, description: $description);
    }

    private static function accountId(Account|int $account): int
    {
        return $account instanceof Account ? $account->id : $account;
    }

    private static function normalise(int|string $amount): string
    {
        $amount = trim((string) $amount);

        if ($amount === '') {
            return '0.00';
        }

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw new InvalidJournalEntry("Amount [{$amount}] must be a non-negative number with at most two decimal places.");
        }

        return bcadd($amount, '0', 2);
    }
}
