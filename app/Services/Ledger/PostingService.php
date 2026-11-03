<?php

namespace App\Services\Ledger;

use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The single entry point for writing to the general ledger. Every module (billing,
 * payments, loans, procurement) posts through here so the books always balance.
 */
class PostingService
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Validate and post a balanced journal entry.
     *
     * @param  list<JournalLineData>  $lines
     *
     * @throws InvalidJournalEntry
     */
    public function post(
        CarbonInterface|string $date,
        string $description,
        array $lines,
        ?string $reference = null,
        ?Model $source = null,
        ?JournalEntry $reverses = null,
    ): JournalEntry {
        $date = Carbon::parse($date)->startOfDay();

        $this->assertLinesBalance($lines);
        $accounts = $this->loadPostableAccounts($lines);

        return DB::transaction(function () use ($date, $description, $lines, $reference, $source, $reverses, $accounts): JournalEntry {
            $period = FiscalPeriod::query()->containing($date)->lockForUpdate()->first();

            if ($period === null) {
                throw new InvalidJournalEntry("No fiscal period covers {$date->toDateString()}. Open the year first.");
            }

            if (! $period->isOpen()) {
                throw new InvalidJournalEntry("Fiscal period {$period->name} is closed.");
            }

            $sequence = (int) JournalEntry::query()->lockForUpdate()->max('sequence') + 1;

            $entry = new JournalEntry([]);
            $entry->forceFill([
                'sequence' => $sequence,
                'entry_date' => $date,
                'fiscal_period_id' => $period->id,
                'description' => $description,
                'reference' => $reference,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'reverses_entry_id' => $reverses?->id,
                'posted_by' => Auth::id(),
            ])->save();

            $entry->lines()->createMany(array_map(fn (JournalLineData $line): array => [
                'account_id' => $line->accountId,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'description' => $line->description,
            ], $lines));

            $this->auditLogger->record($reverses ? 'journal_entry.reversed' : 'journal_entry.posted', $entry, null, [
                'number' => $entry->number,
                'entry_date' => $date->toDateString(),
                'description' => $description,
                'reference' => $reference,
                'reverses' => $reverses?->number,
                'lines' => array_map(fn (JournalLineData $line): array => [
                    'account' => $accounts[$line->accountId]->code,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                ], $lines),
            ]);

            return $entry->load('lines');
        });
    }

    /**
     * Post an entry that exactly cancels an earlier one.
     *
     * @throws InvalidJournalEntry
     */
    public function reverse(JournalEntry $entry, CarbonInterface|string $date, string $reason): JournalEntry
    {
        if ($entry->reverses_entry_id !== null) {
            throw new InvalidJournalEntry("{$entry->number} is itself a reversal and cannot be reversed.");
        }

        if ($entry->reversal()->exists()) {
            throw new InvalidJournalEntry("{$entry->number} has already been reversed.");
        }

        $lines = $entry->lines->map(fn ($line): JournalLineData => new JournalLineData(
            $line->account_id,
            debit: $line->credit,
            credit: $line->debit,
            description: $line->description,
        ))->all();

        return $this->post(
            $date,
            "Reversal of {$entry->number}: {$reason}",
            $lines,
            $entry->reference,
            $entry->source,
            $entry,
        );
    }

    /**
     * @param  list<JournalLineData>  $lines
     */
    private function assertLinesBalance(array $lines): void
    {
        if (count($lines) < 2) {
            throw new InvalidJournalEntry('A journal entry needs at least two lines.');
        }

        $totalDebit = '0';
        $totalCredit = '0';

        foreach ($lines as $index => $line) {
            $hasDebit = bccomp($line->debit, '0', 2) === 1;
            $hasCredit = bccomp($line->credit, '0', 2) === 1;

            if ($hasDebit === $hasCredit) {
                $lineNumber = $index + 1;

                throw new InvalidJournalEntry("Line {$lineNumber} must have either a debit or a credit amount, not both or neither.");
            }

            $totalDebit = bcadd($totalDebit, $line->debit, 2);
            $totalCredit = bcadd($totalCredit, $line->credit, 2);
        }

        if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
            throw new InvalidJournalEntry("Debits ({$totalDebit}) and credits ({$totalCredit}) do not balance.");
        }
    }

    /**
     * @param  list<JournalLineData>  $lines
     * @return Collection<int, Account>
     */
    private function loadPostableAccounts(array $lines): Collection
    {
        $accountIds = array_unique(array_map(fn (JournalLineData $line): int => $line->accountId, $lines));
        $accounts = Account::query()->whereKey($accountIds)->get()->keyBy('id');

        foreach ($accountIds as $accountId) {
            $account = $accounts->get($accountId);

            if ($account === null) {
                throw new InvalidJournalEntry("Account #{$accountId} does not exist.");
            }

            if ($account->is_header) {
                throw new InvalidJournalEntry("Account {$account->code} {$account->name} is a header account and cannot be posted to.");
            }

            if (! $account->is_active) {
                throw new InvalidJournalEntry("Account {$account->code} {$account->name} is inactive.");
            }
        }

        return $accounts;
    }
}
