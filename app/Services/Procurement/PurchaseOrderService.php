<?php

namespace App\Services\Procurement;

use App\Enums\InvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\UserRole;
use App\Enums\WorkOrderStatus;
use App\Models\Account;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\Money;
use App\Services\Numbering\DocumentNumber;
use Illuminate\Support\Facades\DB;

/**
 * Purchase orders: drafted by a requester, submitted, then approved by someone whose
 * approval limit covers the total. Only approved orders can be billed against.
 */
class PurchaseOrderService
{
    /**
     * Close reason recorded when bills reach the order total; voiding a bill reopens such orders.
     */
    public const FULLY_BILLED = 'Fully billed';

    public function __construct(private DocumentNumber $numbers) {}

    /**
     * @param  array{vendor_id: int|string, property_id?: int|string|null, work_order_id?: int|string|null, description: string, order_date: string, expected_date?: string|null, notes?: string|null}  $details
     * @param  list<array{account_id: int|string, description: string, quantity: int|string, unit_price: int|string, vat?: bool}>  $lines
     *
     * @throws ProcurementActionRefused
     */
    public function create(array $details, array $lines, ?User $requester = null): PurchaseOrder
    {
        $attributes = $this->prepareDetails($details);
        $prepared = $this->prepareLines(Vendor::query()->findOrFail($attributes['vendor_id']), $lines);

        return DB::transaction(function () use ($attributes, $prepared, $requester): PurchaseOrder {
            $order = PurchaseOrder::query()->create([
                ...$attributes,
                ...$this->totals($prepared),
                'number' => $this->numbers->next(DocumentNumber::PURCHASE_ORDER),
                'status' => PurchaseOrderStatus::Draft,
                'requested_by' => $requester?->id,
            ]);
            $order->lines()->createMany($prepared);

            return $order;
        });
    }

    /**
     * Replace a draft order's details and lines.
     *
     * @param  array{vendor_id: int|string, property_id?: int|string|null, work_order_id?: int|string|null, description: string, order_date: string, expected_date?: string|null, notes?: string|null}  $details
     * @param  list<array{account_id: int|string, description: string, quantity: int|string, unit_price: int|string, vat?: bool}>  $lines
     *
     * @throws ProcurementActionRefused
     */
    public function update(PurchaseOrder $order, array $details, array $lines): PurchaseOrder
    {
        $this->assertStatus($order, PurchaseOrderStatus::Draft, 'edited');

        $attributes = $this->prepareDetails($details);
        $prepared = $this->prepareLines(Vendor::query()->findOrFail($attributes['vendor_id']), $lines);

        DB::transaction(function () use ($order, $attributes, $prepared): void {
            $order->update([...$attributes, ...$this->totals($prepared)]);
            $order->lines()->delete();
            $order->lines()->createMany($prepared);
        });

        return $order->refresh();
    }

    /**
     * @throws ProcurementActionRefused
     */
    public function submit(PurchaseOrder $order): void
    {
        $this->assertStatus($order, PurchaseOrderStatus::Draft, 'submitted');

        $order->update([
            'status' => PurchaseOrderStatus::PendingApproval,
            'submitted_at' => now(),
            'rejected_by' => null,
            'rejection_reason' => null,
        ]);
    }

    /**
     * @throws ProcurementActionRefused
     */
    public function approve(PurchaseOrder $order, User $approver): void
    {
        if ($reason = $this->approvalBlocker($order, $approver)) {
            throw new ProcurementActionRefused($reason);
        }

        $order->update([
            'status' => PurchaseOrderStatus::Approved,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);
    }

    /**
     * Send the order back to the requester as a draft, with the reason.
     *
     * @throws ProcurementActionRefused
     */
    public function reject(PurchaseOrder $order, User $approver, string $reason): void
    {
        if ($blocker = $this->approvalBlocker($order, $approver)) {
            throw new ProcurementActionRefused($blocker);
        }

        $order->update([
            'status' => PurchaseOrderStatus::Draft,
            'rejected_by' => $approver->id,
            'rejection_reason' => $reason,
            'submitted_at' => null,
        ]);
    }

    /**
     * Why the user may not approve (or reject) this order, or null if they may.
     */
    public function approvalBlocker(PurchaseOrder $order, User $user): ?string
    {
        return match (true) {
            $order->status !== PurchaseOrderStatus::PendingApproval => "{$order->number} is not awaiting approval.",
            $order->requested_by === $user->id && ! $user->hasRole(UserRole::Admin) => 'You raised this order, so someone else must approve it.',
            ! $user->canApprovePurchase((string) $order->total) => $user->purchase_approval_limit === null
                ? 'You have no purchase approval limit. An administrator can set one on your user.'
                : 'KES '.number_format((float) $order->total, 2).' is above your approval limit of KES '.number_format((float) $user->purchase_approval_limit, 2).'.',
            default => null,
        };
    }

    /**
     * @throws ProcurementActionRefused
     */
    public function cancel(PurchaseOrder $order, string $reason): void
    {
        if (in_array($order->status, [PurchaseOrderStatus::Closed, PurchaseOrderStatus::Cancelled], true)) {
            throw new ProcurementActionRefused("{$order->number} is already {$order->status->getLabel()}.");
        }

        if ($this->hasLiveBills($order)) {
            throw new ProcurementActionRefused("{$order->number} has been billed. Close it instead, or void the bills first.");
        }

        $order->update(['status' => PurchaseOrderStatus::Cancelled, 'closed_at' => now(), 'close_reason' => $reason]);
    }

    /**
     * Stop further billing on an approved order, e.g. when the job came in under budget.
     *
     * @throws ProcurementActionRefused
     */
    public function close(PurchaseOrder $order, string $reason): void
    {
        $this->assertStatus($order, PurchaseOrderStatus::Approved, 'closed');

        $order->update(['status' => PurchaseOrderStatus::Closed, 'closed_at' => now(), 'close_reason' => $reason]);
    }

    /**
     * Close an order once bills reach its total, or reopen it when a bill is voided.
     */
    public function refreshBillingStatus(PurchaseOrder $order): void
    {
        $order->refresh();
        $fullyBilled = bccomp($order->billedSubtotal(), (string) $order->subtotal, 2) >= 0;

        if ($order->status === PurchaseOrderStatus::Approved && $fullyBilled) {
            $order->update(['status' => PurchaseOrderStatus::Closed, 'closed_at' => now(), 'close_reason' => self::FULLY_BILLED]);
        } elseif ($order->status === PurchaseOrderStatus::Closed && $order->close_reason === self::FULLY_BILLED && ! $fullyBilled) {
            $order->update(['status' => PurchaseOrderStatus::Approved, 'closed_at' => null, 'close_reason' => null]);
        }
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function prepareDetails(array $details): array
    {
        $workOrder = filled($details['work_order_id'] ?? null) ? WorkOrder::query()->findOrFail($details['work_order_id']) : null;

        if ($workOrder?->status === WorkOrderStatus::Cancelled) {
            throw new ProcurementActionRefused("Work order {$workOrder->number} is cancelled.");
        }

        $vendor = Vendor::query()->findOrFail($details['vendor_id']);

        if (! $vendor->is_active) {
            throw new ProcurementActionRefused("{$vendor->name} is inactive.");
        }

        return [
            'vendor_id' => $vendor->id,
            'property_id' => $workOrder?->property_id ?? (filled($details['property_id'] ?? null) ? (int) $details['property_id'] : null),
            'work_order_id' => $workOrder?->id,
            'description' => $details['description'],
            'order_date' => $details['order_date'],
            'expected_date' => $details['expected_date'] ?? null,
            'notes' => $details['notes'] ?? null,
        ];
    }

    /**
     * @param  list<array{account_id: int|string, description: string, quantity: int|string, unit_price: int|string, vat?: bool}>  $lines
     * @return list<array{account_id: int, description: string, quantity: string, unit_price: string, amount: string, vat_rate: string, vat_amount: string}>
     */
    private function prepareLines(Vendor $vendor, array $lines): array
    {
        if ($lines === []) {
            throw new ProcurementActionRefused('A purchase order needs at least one line.');
        }

        $vatRate = (string) config('ledger.vat_rate');

        return array_map(function (array $line) use ($vendor, $vatRate): array {
            $quantity = bcadd((string) $line['quantity'], '0', 2);
            $unitPrice = Money::round((string) $line['unit_price']);

            if (! Money::isPositive($quantity) || ! Money::isPositive($unitPrice)) {
                throw new ProcurementActionRefused("\"{$line['description']}\" needs a quantity and price greater than zero.");
            }

            $line['vat'] = (bool) ($line['vat'] ?? false);

            if ($line['vat'] && ! $vendor->is_vat_registered) {
                throw new ProcurementActionRefused("{$vendor->name} is not VAT registered, so no VAT can be added.");
            }

            $this->assertExpenseAccount((int) $line['account_id']);
            $amount = Money::multiply($unitPrice, $quantity);

            return [
                'account_id' => (int) $line['account_id'],
                'description' => $line['description'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'amount' => $amount,
                'vat_rate' => $line['vat'] ? $vatRate : '0.00',
                'vat_amount' => $line['vat'] ? Money::percentOf($amount, $vatRate) : '0.00',
            ];
        }, array_values($lines));
    }

    /**
     * @param  list<array{amount: string, vat_amount: string}>  $lines
     * @return array{subtotal: string, vat_amount: string, total: string}
     */
    private function totals(array $lines): array
    {
        $subtotal = Money::sum(array_column($lines, 'amount'));
        $vat = Money::sum(array_column($lines, 'vat_amount'));

        return ['subtotal' => $subtotal, 'vat_amount' => $vat, 'total' => bcadd($subtotal, $vat, 2)];
    }

    private function assertExpenseAccount(int $accountId): void
    {
        $account = Account::query()->postable()->find($accountId);

        if ($account === null || ! in_array($account->type, SupplierBillService::chargeableAccountTypes(), true)) {
            throw new ProcurementActionRefused('Purchases must be charged to an active expense or asset account.');
        }
    }

    private function assertStatus(PurchaseOrder $order, PurchaseOrderStatus $status, string $action): void
    {
        if ($order->status !== $status) {
            throw new ProcurementActionRefused("{$order->number} is {$order->status->getLabel()}, so it cannot be {$action}.");
        }
    }

    private function hasLiveBills(PurchaseOrder $order): bool
    {
        return $order->bills()->where('status', '!=', InvoiceStatus::Void)->exists();
    }
}
