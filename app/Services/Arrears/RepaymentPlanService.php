<?php

namespace App\Services\Arrears;

use App\Enums\RepaymentPlanStatus;
use App\Models\RepaymentPlan;
use App\Models\Tenant;
use App\Services\Leasing\LeaseActionRefused;
use App\Services\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Instalment agreements for clearing arrears. Progress is measured by how much of the
 * debt that existed on the agreement date has since been settled; payments are applied
 * oldest-first, so money received clears the agreed arrears before newer rent.
 */
class RepaymentPlanService
{
    /**
     * @throws LeaseActionRefused
     */
    public function create(Tenant $tenant, CarbonInterface|string $firstDueDate, int $instalments, int $intervalMonths = 1, int $graceDays = 3, ?string $terms = null): RepaymentPlan
    {
        $arrears = $tenant->balance();

        if (! Money::isPositive($arrears)) {
            throw new LeaseActionRefused("{$tenant->name} has no arrears to schedule.");
        }

        if ($tenant->repaymentPlans()->where('status', RepaymentPlanStatus::Active)->exists()) {
            throw new LeaseActionRefused("{$tenant->name} already has an active repayment plan.");
        }

        if ($instalments < 1 || $instalments > 36) {
            throw new LeaseActionRefused('Choose between 1 and 36 instalments.');
        }

        return DB::transaction(function () use ($tenant, $firstDueDate, $instalments, $intervalMonths, $graceDays, $terms, $arrears): RepaymentPlan {
            $plan = RepaymentPlan::query()->create([
                'tenant_id' => $tenant->id,
                'arrears_amount' => $arrears,
                'agreed_on' => today(),
                'grace_days' => $graceDays,
                'terms' => $terms,
                'created_by' => Auth::id(),
            ]);

            $each = bcdiv($arrears, (string) $instalments, 2);
            $firstDueDate = Carbon::parse($firstDueDate);

            for ($sequence = 1; $sequence <= $instalments; $sequence++) {
                $plan->instalments()->create([
                    'sequence' => $sequence,
                    'due_date' => $firstDueDate->copy()->addMonthsNoOverflow(($sequence - 1) * $intervalMonths),
                    'amount' => $sequence === $instalments ? bcsub($arrears, bcmul($each, (string) ($instalments - 1), 2), 2) : $each,
                ]);
            }

            return $plan;
        });
    }

    /**
     * Agreed arrears still unpaid: outstanding invoices issued on or before the agreement date.
     */
    public function remaining(RepaymentPlan $plan): string
    {
        $outstanding = $plan->tenant->invoices()
            ->outstanding()
            ->whereDate('issue_date', '<=', $plan->agreed_on)
            ->get()
            ->sum(fn ($invoice): float => (float) $invoice->balanceDue());

        return Money::min(Money::round((string) $outstanding), (string) $plan->arrears_amount);
    }

    public function paidSoFar(RepaymentPlan $plan): string
    {
        return bcsub((string) $plan->arrears_amount, $this->remaining($plan), 2);
    }

    /**
     * Total of instalments whose due date plus grace has passed.
     */
    public function overdueSchedule(RepaymentPlan $plan, CarbonInterface $on): string
    {
        return Money::sum($plan->instalments
            ->filter(fn ($instalment): bool => $instalment->due_date->copy()->addDays($plan->grace_days)->lt($on))
            ->map(fn ($instalment): string => (string) $instalment->amount));
    }

    /**
     * Mark the plan completed once the arrears are cleared, or defaulted if it has fallen behind.
     */
    public function review(RepaymentPlan $plan, CarbonInterface $on): RepaymentPlanStatus
    {
        if ($plan->status !== RepaymentPlanStatus::Active) {
            return $plan->status;
        }

        if (! Money::isPositive($this->remaining($plan))) {
            $plan->update(['status' => RepaymentPlanStatus::Completed, 'closed_at' => now(), 'close_reason' => 'Arrears cleared']);
        } elseif (bccomp($this->paidSoFar($plan), $this->overdueSchedule($plan, $on), 2) < 0) {
            $plan->update(['status' => RepaymentPlanStatus::Defaulted, 'closed_at' => now(), 'close_reason' => 'Instalments missed; penalties resume']);
        }

        return $plan->status;
    }

    /**
     * @return Collection<int, RepaymentPlan> Plans whose status changed
     */
    public function reviewAll(CarbonInterface $on): Collection
    {
        return RepaymentPlan::query()
            ->where('status', RepaymentPlanStatus::Active)
            ->with(['tenant', 'instalments'])
            ->get()
            ->filter(fn (RepaymentPlan $plan): bool => $this->review($plan, $on) !== RepaymentPlanStatus::Active)
            ->values();
    }

    public function cancel(RepaymentPlan $plan, string $reason): void
    {
        $plan->update(['status' => RepaymentPlanStatus::Cancelled, 'closed_at' => now(), 'close_reason' => $reason]);
    }
}
