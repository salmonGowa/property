<?php

namespace Tests\Feature\Services\Ledger;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Services\Ledger\JournalLineData;
use App\Services\Ledger\PostingService;
use App\Services\Ledger\TrialBalance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TrialBalanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_shows_net_balances_that_total_equally_and_excludes_later_postings(): void
    {
        FiscalPeriod::factory()->forMonth('2026-10-01')->create();
        $bank = Account::factory()->ofType(AccountType::Asset)->create(['code' => '1100']);
        $receivables = Account::factory()->ofType(AccountType::Asset)->create(['code' => '1200']);
        $rent = Account::factory()->ofType(AccountType::Revenue)->create(['code' => '4110']);
        Account::factory()->create(['code' => '1300']);
        $posting = app(PostingService::class);

        $posting->post('2026-10-01', 'Rent billed', [JournalLineData::debit($receivables, '50000'), JournalLineData::credit($rent, '50000')]);
        $posting->post('2026-10-05', 'Rent paid', [JournalLineData::debit($bank, '30000'), JournalLineData::credit($receivables, '30000')]);
        $posting->post('2026-10-20', 'Later rent', [JournalLineData::debit($receivables, '1000'), JournalLineData::credit($rent, '1000')]);

        $trialBalance = app(TrialBalance::class);
        $rows = $trialBalance->rows(Carbon::parse('2026-10-10'));

        $this->assertSame(
            [['1100', '30000.00', '0.00'], ['1200', '20000.00', '0.00'], ['4110', '0.00', '50000.00']],
            $rows->map(fn (array $row): array => [$row['account']->code, $row['debit'], $row['credit']])->all(),
        );
        $this->assertSame(['debit' => '50000.00', 'credit' => '50000.00'], $trialBalance->totals($rows));
    }
}
