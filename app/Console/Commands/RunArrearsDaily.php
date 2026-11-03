<?php

namespace App\Console\Commands;

use App\Services\Arrears\PenaltyService;
use App\Services\Arrears\RepaymentPlanService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

#[Signature('arrears:run {--date= : Assess as at this date (default: today)} {--preview : Show penalties that would be raised without raising them}')]
#[Description('Review repayment plans, then raise late-payment penalties under each lease\'s penalty rule')]
class RunArrearsDaily extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PenaltyService $penalties, RepaymentPlanService $plans): int
    {
        $on = Carbon::parse($this->option('date') ?? today());

        if ($this->option('preview')) {
            $preview = $penalties->preview($on);

            $this->table(['Invoice', 'Tenant', 'Rule', 'Overdue', 'Penalty'], $preview->map(fn ($assessment): array => [
                $assessment->invoice->number,
                $assessment->invoice->tenant->name,
                $assessment->rule->name,
                Number::format((float) $assessment->basis, 2),
                Number::format((float) $assessment->total(), 2),
            ])->all());

            return self::SUCCESS;
        }

        foreach ($plans->reviewAll($on) as $plan) {
            $this->line("Repayment plan #{$plan->id} ({$plan->tenant->name}): {$plan->status->getLabel()}");
        }

        $result = $penalties->assess($on);

        $this->info(count($result->invoices).' penalty invoice(s) raised totalling KES '.Number::format((float) $result->total(), 2).'.');

        foreach ($result->failures as $invoice => $message) {
            $this->warn("{$invoice}: {$message}");
        }

        return $result->failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
