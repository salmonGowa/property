<?php

namespace App\Services\Debt;

use App\Enums\AccountType;
use App\Enums\ChargeType;
use App\Enums\CreditNoteType;
use App\Enums\InvoiceStatus;
use App\Models\CreditNote;
use App\Models\InvoiceLine;
use App\Models\SupplierBillLine;
use App\Services\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Net operating income for a set of properties (or the whole portfolio) over a period:
 * income billed to tenants, less credits and write-offs, less operating costs billed by
 * suppliers. Deposits, VAT, capital spend (asset accounts) and loan interest are excluded.
 */
class NetOperatingIncome
{
    /**
     * @param  list<int>|null  $propertyIds  Null for the whole portfolio
     * @return array{income: string, credits: string, operating_costs: string, bad_debts: string, noi: string}
     */
    public function between(CarbonInterface $from, CarbonInterface $to, ?array $propertyIds = null): array
    {
        $onProperties = fn (Builder $invoices) => $propertyIds === null ? $invoices : $invoices->whereHas('lease.unit', fn (Builder $units) => $units->whereIn('property_id', $propertyIds));

        $income = InvoiceLine::query()
            ->where('charge_type', '!=', ChargeType::Deposit)
            ->whereHas('invoice', fn (Builder $invoices) => $onProperties($invoices->where('status', '!=', InvoiceStatus::Void)->whereBetween('issue_date', [$from, $to])))
            ->sum('amount');

        $creditNotes = CreditNote::query()
            ->whereBetween('issue_date', [$from, $to])
            ->whereHas('invoice', fn (Builder $invoices) => $onProperties($invoices));

        $credits = (clone $creditNotes)
            ->whereIn('type', array_filter(CreditNoteType::cases(), fn (CreditNoteType $type): bool => $type->reversesIncome()))
            ->selectRaw('COALESCE(SUM(amount - vat_amount), 0) as net')
            ->value('net');

        $badDebts = (clone $creditNotes)->where('type', CreditNoteType::WriteOff)->sum('amount');

        $operatingCosts = SupplierBillLine::query()
            ->whereHas('account', fn (Builder $accounts) => $accounts->where('type', AccountType::Expense))
            ->whereHas('bill', fn (Builder $bills) => $bills
                ->where('status', '!=', InvoiceStatus::Void)
                ->whereBetween('bill_date', [$from, $to])
                ->when($propertyIds !== null, fn (Builder $bills) => $bills->whereIn('property_id', $propertyIds)))
            ->sum('amount');

        $income = Money::round((string) $income);
        $credits = Money::round((string) $credits);
        $operatingCosts = Money::round((string) $operatingCosts);
        $badDebts = Money::round((string) $badDebts);

        return [
            'income' => $income,
            'credits' => $credits,
            'operating_costs' => $operatingCosts,
            'bad_debts' => $badDebts,
            'noi' => bcsub(bcsub(bcsub($income, $credits, 2), $operatingCosts, 2), $badDebts, 2),
        ];
    }
}
