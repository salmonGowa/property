<?php

namespace Tests\Feature\Services\Ledger;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Ledger\InvalidJournalEntry;
use App\Services\Ledger\JournalLineData;
use App\Services\Ledger\PostingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class PostingServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private PostingService $posting;

    private Account $receivables;

    private Account $rentIncome;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-15');
        FiscalPeriod::factory()->forMonth('2026-10-01')->create();

        $this->posting = app(PostingService::class);
        $this->receivables = Account::factory()->ofType(AccountType::Asset)->create(['code' => '1200']);
        $this->rentIncome = Account::factory()->ofType(AccountType::Revenue)->create(['code' => '4110']);
    }

    public function test_balanced_entry_is_posted_with_its_lines_and_poster(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $entry = $this->posting->post('2026-10-01', 'October rent - Unit 4B', [
            JournalLineData::debit($this->receivables, '85000.50'),
            JournalLineData::credit($this->rentIncome, '85000.50'),
        ], reference: 'INV-0001');

        $this->assertSame('JE-000001', $entry->number);
        $this->assertSame($user->id, $entry->posted_by);
        $this->assertSame('INV-0001', $entry->reference);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $entry->id, 'account_id' => $this->receivables->id, 'debit' => '85000.50', 'credit' => '0.00']);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $entry->id, 'account_id' => $this->rentIncome->id, 'debit' => '0.00', 'credit' => '85000.50']);
    }

    public function test_entries_are_numbered_sequentially(): void
    {
        $first = $this->postRent('1000');
        $second = $this->postRent('2000');

        $this->assertSame([1, 2], [$first->sequence, $second->sequence]);
    }

    public function test_posting_updates_account_balances_on_their_normal_side(): void
    {
        $this->postRent('1000.25');
        $this->postRent('500');

        $this->assertSame('1500.25', $this->receivables->balance());
        $this->assertSame('1500.25', $this->rentIncome->balance());
    }

    public function test_rejects_entry_whose_debits_and_credits_differ(): void
    {
        $this->expectException(InvalidJournalEntry::class);
        $this->expectExceptionMessage('do not balance');

        $this->posting->post('2026-10-01', 'Unbalanced', [
            JournalLineData::debit($this->receivables, '100.00'),
            JournalLineData::credit($this->rentIncome, '99.99'),
        ]);
    }

    public function test_rejects_entry_with_a_single_line(): void
    {
        $this->expectException(InvalidJournalEntry::class);

        $this->posting->post('2026-10-01', 'One line', [JournalLineData::debit($this->receivables, '100')]);
    }

    public function test_rejects_line_with_both_debit_and_credit(): void
    {
        $this->expectException(InvalidJournalEntry::class);
        $this->expectExceptionMessage('Line 1');

        $this->posting->post('2026-10-01', 'Both sides', [
            new JournalLineData($this->receivables->id, debit: '100', credit: '100'),
            JournalLineData::credit($this->rentIncome, '0'),
        ]);
    }

    public function test_rejects_amount_with_more_than_two_decimal_places(): void
    {
        $this->expectException(InvalidJournalEntry::class);

        JournalLineData::debit($this->receivables, '10.005');
    }

    public function test_rejects_posting_to_header_account(): void
    {
        $header = Account::factory()->header()->create();

        $this->expectException(InvalidJournalEntry::class);
        $this->expectExceptionMessage('header account');

        $this->posting->post('2026-10-01', 'To header', [
            JournalLineData::debit($header, '100'),
            JournalLineData::credit($this->rentIncome, '100'),
        ]);
    }

    public function test_rejects_posting_to_inactive_account(): void
    {
        $inactive = Account::factory()->inactive()->create();

        $this->expectException(InvalidJournalEntry::class);
        $this->expectExceptionMessage('inactive');

        $this->posting->post('2026-10-01', 'To inactive', [
            JournalLineData::debit($inactive, '100'),
            JournalLineData::credit($this->rentIncome, '100'),
        ]);
    }

    public function test_rejects_date_outside_any_fiscal_period(): void
    {
        $this->expectException(InvalidJournalEntry::class);
        $this->expectExceptionMessage('No fiscal period covers 2026-11-01');

        $this->postRent('100', '2026-11-01');
    }

    public function test_rejects_date_in_closed_period(): void
    {
        FiscalPeriod::factory()->forMonth('2026-09-01')->closed()->create();

        $this->expectException(InvalidJournalEntry::class);
        $this->expectExceptionMessage('closed');

        $this->postRent('100', '2026-09-30');
    }

    public function test_failed_posting_writes_nothing(): void
    {
        try {
            $this->postRent('100', '2026-11-01');
        } catch (InvalidJournalEntry) {
        }

        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_lines', 0);
    }

    public function test_reversal_posts_mirrored_lines_and_zeroes_balances(): void
    {
        $original = $this->postRent('2500');

        $reversal = $this->posting->reverse($original, '2026-10-20', 'Billed to wrong tenant');

        $this->assertSame($original->id, $reversal->reverses_entry_id);
        $this->assertSame('Reversal of JE-000001: Billed to wrong tenant', $reversal->description);
        $this->assertSame('0.00', $this->receivables->balance());
        $this->assertSame('0.00', $this->rentIncome->balance());
    }

    public function test_rejects_reversing_an_entry_twice(): void
    {
        $original = $this->postRent('2500');
        $this->posting->reverse($original, '2026-10-20', 'First');

        $this->expectException(InvalidJournalEntry::class);
        $this->expectExceptionMessage('already been reversed');

        $this->posting->reverse($original, '2026-10-21', 'Second');
    }

    public function test_rejects_reversing_a_reversal(): void
    {
        $reversal = $this->posting->reverse($this->postRent('2500'), '2026-10-20', 'Mistake');

        $this->expectException(InvalidJournalEntry::class);
        $this->expectExceptionMessage('is itself a reversal');

        $this->posting->reverse($reversal, '2026-10-21', 'Undo');
    }

    public function test_posting_is_recorded_in_audit_log(): void
    {
        $entry = $this->postRent('750');

        $log = AuditLog::query()->where('event', 'journal_entry.posted')->sole();

        $this->assertSame($entry->id, (int) $log->auditable_id);
        $this->assertSame([
            ['account' => '1200', 'debit' => '750.00', 'credit' => '0.00'],
            ['account' => '4110', 'debit' => '0.00', 'credit' => '750.00'],
        ], $log->newValues()['lines']);
    }

    public function test_posted_entry_cannot_be_changed_through_the_model(): void
    {
        $entry = $this->postRent('100');

        $this->expectException(LogicException::class);

        $entry->description = 'Edited';
        $entry->save();
    }

    public function test_posted_lines_cannot_be_changed_directly_in_the_database(): void
    {
        $entry = $this->postRent('100');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('journal_lines rows are immutable');

        DB::table('journal_lines')->where('journal_entry_id', $entry->id)->update(['debit' => '999']);
    }

    private function postRent(string $amount, string $date = '2026-10-01'): JournalEntry
    {
        return $this->posting->post($date, 'Rent', [
            JournalLineData::debit($this->receivables, $amount),
            JournalLineData::credit($this->rentIncome, $amount),
        ]);
    }
}
