<?php

namespace App\Services\Collections;

use App\Enums\MpesaTransactionStatus;
use App\Enums\PaymentMethod;
use App\Models\MpesaTransaction;
use App\Models\Tenant;
use App\Services\Ledger\InvalidJournalEntry;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns Safaricom C2B (Paybill) confirmations into tenant receipts. Payers quote the
 * tenant's account number (e.g. T00042) as the Paybill account; anything that cannot
 * be matched waits in the M-Pesa inbox for a person to match.
 */
class MpesaC2bService
{
    public function __construct(private PaymentService $payments) {}

    /**
     * Record a confirmation payload. Repeated deliveries of the same TransID are ignored.
     *
     * @param  array<string, mixed>  $payload
     */
    public function ingest(array $payload): MpesaTransaction
    {
        $transaction = DB::transaction(function () use ($payload): MpesaTransaction {
            $existing = MpesaTransaction::query()->where('trans_id', $payload['TransID'])->lockForUpdate()->first();

            if ($existing !== null) {
                return $existing;
            }

            return MpesaTransaction::query()->create([
                'trans_id' => $payload['TransID'],
                'transaction_type' => $payload['TransactionType'] ?? null,
                'amount' => (string) $payload['TransAmount'],
                'bill_ref_number' => isset($payload['BillRefNumber']) ? trim((string) $payload['BillRefNumber']) : null,
                'msisdn' => $payload['MSISDN'] ?? null,
                'payer_name' => trim(implode(' ', array_filter([$payload['FirstName'] ?? null, $payload['MiddleName'] ?? null, $payload['LastName'] ?? null]))) ?: null,
                'business_short_code' => $payload['BusinessShortCode'] ?? null,
                'transacted_at' => $this->parseTransTime((string) ($payload['TransTime'] ?? '')),
                'status' => MpesaTransactionStatus::Unmatched,
                'payload' => $payload,
            ]);
        });

        if ($transaction->status === MpesaTransactionStatus::Unmatched && $transaction->wasRecentlyCreated) {
            $tenant = $this->findTenant($transaction->bill_ref_number);

            if ($tenant !== null) {
                try {
                    $this->match($transaction, $tenant);
                } catch (InvalidJournalEntry $exception) {
                    Log::warning("M-Pesa {$transaction->trans_id} left unmatched: {$exception->getMessage()}");
                }
            }
        }

        return $transaction->refresh();
    }

    /**
     * Receipt the transaction to a tenant.
     *
     * @throws InvalidJournalEntry
     */
    public function match(MpesaTransaction $transaction, Tenant $tenant): void
    {
        if ($transaction->status === MpesaTransactionStatus::Matched) {
            throw new InvalidJournalEntry("M-Pesa {$transaction->trans_id} is already matched.");
        }

        DB::transaction(function () use ($transaction, $tenant): void {
            $payment = $this->payments->record(
                $tenant,
                $transaction->transacted_at->toDateString(),
                PaymentMethod::Mpesa,
                (string) $transaction->amount,
                $transaction->trans_id,
                trim("Paybill from {$transaction->payer_name} {$transaction->msisdn}"),
            );

            $transaction->update(['status' => MpesaTransactionStatus::Matched, 'payment_id' => $payment->id]);
        });
    }

    private function findTenant(?string $billReference): ?Tenant
    {
        if (blank($billReference)) {
            return null;
        }

        return Tenant::query()->where('account_number', strtoupper(str_replace(' ', '', $billReference)))->first();
    }

    /**
     * Safaricom sends TransTime as YmdHis in East Africa Time.
     */
    private function parseTransTime(string $transTime): Carbon
    {
        try {
            $parsed = Carbon::createFromFormat('YmdHis', $transTime, 'Africa/Nairobi');
        } catch (InvalidFormatException) {
            $parsed = null;
        }

        return ($parsed ?: now('Africa/Nairobi'))->setTimezone(config('app.timezone'));
    }
}
