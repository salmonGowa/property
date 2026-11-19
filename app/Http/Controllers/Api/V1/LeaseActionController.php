<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangeLeasePenaltyRuleRequest;
use App\Http\Requests\Api\V1\RenewLeaseRequest;
use App\Http\Requests\Api\V1\SettleLeaseDepositRequest;
use App\Http\Requests\Api\V1\TerminateLeaseRequest;
use App\Http\Resources\V1\LeaseResource;
use App\Models\Lease;
use App\Services\Leasing\DepositService;
use App\Services\Leasing\LeaseActionRefused;
use App\Services\Leasing\LeaseService;
use App\Services\Ledger\InvalidJournalEntry;
use App\Services\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Moves a lease through its life: activate, renew, terminate, and settle the deposit at move-out.
 * Refusals from the leasing services (unit already let, closed fiscal period...) come back as 422s.
 */
class LeaseActionController extends Controller
{
    public function __construct(
        private LeaseService $leases,
        private DepositService $deposits,
    ) {}

    public function activate(Lease $lease): LeaseResource
    {
        Gate::authorize('activate', $lease);

        $invoicesBefore = $lease->invoices()->count();

        try {
            $this->leases->activate($lease);
        } catch (LeaseActionRefused|InvalidJournalEntry $exception) {
            throw ValidationException::withMessages(['lease' => $exception->getMessage()]);
        }

        return LeaseResource::detailed($lease)->additional([
            'meta' => ['invoicesIssued' => $lease->invoices()->count() - $invoicesBefore],
        ]);
    }

    public function terminate(TerminateLeaseRequest $request, Lease $lease): LeaseResource
    {
        try {
            $adjustments = $this->leases->terminate($lease, $request->validated('terminated_on'), $request->validated('reason'));
        } catch (LeaseActionRefused|InvalidJournalEntry $exception) {
            throw ValidationException::withMessages(['terminated_on' => $exception->getMessage()]);
        }

        return LeaseResource::detailed($lease)->additional([
            'meta' => ['invoicesAdjusted' => count($adjustments)],
        ]);
    }

    public function renew(RenewLeaseRequest $request, Lease $lease): JsonResponse
    {
        try {
            $renewal = $this->leases->renew($lease, $request->validated('start_date'), $request->validated('end_date'), (string) $request->validated('rent_amount'));
        } catch (LeaseActionRefused $exception) {
            throw ValidationException::withMessages(['lease' => $exception->getMessage()]);
        }

        return LeaseResource::detailed($renewal)->response()->setStatusCode(201);
    }

    public function changePenaltyRule(ChangeLeasePenaltyRuleRequest $request, Lease $lease): LeaseResource
    {
        $lease->update([
            'penalties_exempt' => $request->boolean('penalties_exempt'),
            'penalty_rule_id' => $request->validated('penalty_rule_id'),
        ]);

        return LeaseResource::detailed($lease);
    }

    public function settleDeposit(SettleLeaseDepositRequest $request, Lease $lease): JsonResponse
    {
        if (! Money::isPositive($this->deposits->heldFor($lease))) {
            throw ValidationException::withMessages(['lease' => 'No deposit is held for this lease.']);
        }

        try {
            $settlement = $this->deposits->settle(
                $lease,
                $request->validated('date'),
                array_values($request->validated('deductions', [])),
                $request->filled('refund_method') ? PaymentMethod::from($request->validated('refund_method')) : null,
                $request->validated('refund_reference'),
            );
        } catch (LeaseActionRefused|InvalidJournalEntry $exception) {
            throw ValidationException::withMessages(['lease' => $exception->getMessage()]);
        }

        return response()->json([
            'data' => [
                'held' => $settlement->held,
                'applied' => $settlement->applied,
                'refunded' => $settlement->refunded,
                'stillHeld' => $settlement->stillHeld,
            ],
        ]);
    }
}
