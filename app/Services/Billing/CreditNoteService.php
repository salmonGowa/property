<?php

namespace App\Services\Billing;

use App\Enums\CreditNoteType;
use App\Enums\InvoiceStatus;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\Collections\AllocationService;
use App\Services\Ledger\InvalidJournalEntry;
use App\Services\Ledger\JournalLineData;
use App\Services\Ledger\LedgerAccounts;
use App\Services\Ledger\PostingService;
use App\Services\Money;
use App\Services\Numbering\DocumentNumber;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Credit notes reduce what a tenant owes. Adjustments, waivers and termination credits
 * reverse the invoice's income and VAT in proportion; write-offs are charged to bad debts.
 * The credit settles its own invoice first and any excess becomes credit on the account.
 */
class CreditNoteService
{
    public function __construct(
        private PostingService $posting,
        private LedgerAccounts $accounts,
        private DocumentNumber $numbers,
        private AllocationService $allocations,
    ) {}

    /**
     * @param  string  $amount  Amount to credit, including VAT
     *
     * @throws InvalidJournalEntry
     */
    public function issue(Invoice $invoice, CreditNoteType $type, string $amount, string $reason, CarbonInterface|string|null $issueDate = null): CreditNote
    {
        $amount = Money::round($amount);
        $issueDate = Carbon::parse($issueDate ?? today());

        if ($invoice->status === InvoiceStatus::Void) {
            throw new InvalidJournalEntry("{$invoice->number} is void and cannot be credited.");
        }

        if (! Money::isPositive($amount)) {
            throw new InvalidJournalEntry('The credit amount must be greater than zero.');
        }

        $creditable = $this->creditableAmount($invoice);

        if (bccomp($amount, $creditable, 2) > 0) {
            throw new InvalidJournalEntry("At most KES {$creditable} of {$invoice->number} can still be credited.");
        }

        $shares = $type->reversesIncome() ? $this->shareAcrossLines($invoice, $amount) : [];
        $vat = Money::sum(array_column($shares, 'vat'));

        $creditNote = DB::transaction(function () use ($invoice, $type, $amount, $reason, $issueDate, $shares, $vat): CreditNote {
            $creditNote = CreditNote::query()->create([
                'number' => $this->numbers->next(DocumentNumber::CREDIT_NOTE),
                'type' => $type,
                'tenant_id' => $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'issue_date' => $issueDate,
                'amount' => $amount,
                'vat_amount' => $vat,
                'reason' => $reason,
                'created_by' => Auth::id(),
            ]);

            $journalLines = $type->reversesIncome()
                ? $this->incomeReversalLines($shares, $vat)
                : [JournalLineData::debit($this->accounts->get('bad_debts'), $amount, "Write-off {$invoice->number}")];

            $journalLines[] = JournalLineData::credit($this->accounts->get('receivable'), $amount, $invoice->tenant->name);

            $entry = $this->posting->post($issueDate, "{$creditNote->number} {$type->getLabel()} on {$invoice->number}: {$reason}", $journalLines, $creditNote->number, $creditNote);
            $creditNote->update(['journal_entry_id' => $entry->id]);

            return $creditNote;
        });

        $this->allocations->allocate($creditNote, $invoice);

        return $creditNote->refresh();
    }

    /**
     * What is left of the invoice to credit: its total less earlier credit notes.
     */
    public function creditableAmount(Invoice $invoice): string
    {
        return bcsub((string) $invoice->total, (string) $invoice->creditNotes()->sum('amount'), 2);
    }

    /**
     * Split a credit across the invoice lines in proportion to each line's total,
     * putting any rounding difference on the largest line.
     *
     * @return list<array{account_id: int, net: string, vat: string, description: string}>
     */
    private function shareAcrossLines(Invoice $invoice, string $amount): array
    {
        $ratio = bcdiv($amount, (string) $invoice->total, 10);
        $lines = $invoice->lines->sortByDesc(fn (InvoiceLine $line): float => (float) $line->total())->values();

        $shares = $lines->map(fn (InvoiceLine $line): array => [
            'account_id' => $line->account_id,
            'net' => Money::multiply((string) $line->amount, $ratio),
            'vat' => Money::multiply((string) $line->vat_amount, $ratio),
            'description' => $line->description,
        ])->all();

        $difference = bcsub($amount, Money::sum(array_map(fn (array $share): string => bcadd($share['net'], $share['vat'], 2), $shares)), 2);
        $shares[0]['net'] = bcadd($shares[0]['net'], $difference, 2);

        return $shares;
    }

    /**
     * @param  list<array{account_id: int, net: string, vat: string, description: string}>  $shares
     * @return list<JournalLineData>
     */
    private function incomeReversalLines(array $shares, string $vat): array
    {
        $lines = [];

        foreach ($shares as $share) {
            if (Money::isPositive($share['net'])) {
                $lines[] = JournalLineData::debit($share['account_id'], $share['net'], $share['description']);
            }
        }

        if (Money::isPositive($vat)) {
            $lines[] = JournalLineData::debit($this->accounts->get('vat_output'), $vat, 'VAT output reversed');
        }

        return $lines;
    }
}
