<?php

namespace App\Services\Ledger;

use App\Enums\PaymentMethod;
use App\Models\Account;
use RuntimeException;

/**
 * Resolves the configured posting accounts (config/ledger.php) to Account models.
 */
class LedgerAccounts
{
    /**
     * @var array<string, Account>
     */
    private array $resolved = [];

    public function get(string $key): Account
    {
        return $this->resolved[$key] ??= $this->find((string) config("ledger.accounts.{$key}"), $key);
    }

    public function depositAccountFor(PaymentMethod $method): Account
    {
        return $this->get("deposit.{$method->value}");
    }

    public function disbursementAccountFor(PaymentMethod $method): Account
    {
        return $this->get("disbursement.{$method->value}");
    }

    private function find(string $code, string $key): Account
    {
        $account = Account::query()->where('code', $code)->first();

        if ($account === null) {
            throw new RuntimeException("Posting account [{$key}] is set to code [{$code}], which is not in the chart of accounts.");
        }

        return $account;
    }
}
