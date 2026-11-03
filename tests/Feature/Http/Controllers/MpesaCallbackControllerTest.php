<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\MpesaTransactionStatus;
use App\Enums\PaymentMethod;
use App\Models\MpesaTransaction;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\SetsUpLedger;
use Tests\TestCase;

class MpesaCallbackControllerTest extends TestCase
{
    use LazilyRefreshDatabase, SetsUpLedger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger();
        config(['services.mpesa.callback_token' => 'secret-token']);
    }

    public function test_returns_404_when_the_token_is_wrong(): void
    {
        $this->postJson('/mpesa/wrong-token/c2b/confirmation', $this->payload('T00001'))->assertNotFound();

        $this->assertDatabaseCount('mpesa_transactions', 0);
    }

    public function test_validation_accepts_the_payment(): void
    {
        $this->postJson('/mpesa/secret-token/c2b/validation', $this->payload('T00001'))
            ->assertOk()
            ->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    public function test_confirmation_quoting_a_tenant_account_creates_a_receipt(): void
    {
        $tenant = Tenant::factory()->create();

        $this->postJson('/mpesa/secret-token/c2b/confirmation', $this->payload(strtolower($tenant->account_number)))->assertOk();

        $transaction = MpesaTransaction::query()->sole();
        $payment = Payment::query()->sole();
        $this->assertSame(MpesaTransactionStatus::Matched, $transaction->status);
        $this->assertSame($payment->id, $transaction->payment_id);
        $this->assertSame(PaymentMethod::Mpesa, $payment->method);
        $this->assertSame('SJK3H5T9QW', $payment->reference);
        $this->assertSame('2500.00', $payment->amount);
        $this->assertSame('2026-10-14', $payment->received_on->toDateString());
    }

    public function test_repeated_delivery_of_the_same_transaction_is_recorded_once(): void
    {
        $tenant = Tenant::factory()->create();

        $this->postJson('/mpesa/secret-token/c2b/confirmation', $this->payload($tenant->account_number))->assertOk();
        $this->postJson('/mpesa/secret-token/c2b/confirmation', $this->payload($tenant->account_number))->assertOk();

        $this->assertDatabaseCount('mpesa_transactions', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_unknown_account_waits_in_the_inbox(): void
    {
        $this->postJson('/mpesa/secret-token/c2b/confirmation', $this->payload('HOUSE 4B'))->assertOk();

        $this->assertSame(MpesaTransactionStatus::Unmatched, MpesaTransaction::query()->sole()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * @return array<string, string>
     */
    private function payload(string $billReference): array
    {
        return [
            'TransactionType' => 'Pay Bill',
            'TransID' => 'SJK3H5T9QW',
            'TransTime' => '20261014013015',
            'TransAmount' => '2500.00',
            'BusinessShortCode' => '600999',
            'BillRefNumber' => $billReference,
            'MSISDN' => '2547XXXXX123',
            'FirstName' => 'JANE',
            'LastName' => 'WANJIKU',
        ];
    }
}
