<?php

namespace App\Console\Commands;

use App\Services\Maintenance\MaintenanceScheduleService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('maintenance:run {--date= : Raise work orders due as at this date (default: today)}')]
#[Description('Raise work orders for preventive maintenance schedules that are falling due')]
class RunMaintenanceSchedules extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MaintenanceScheduleService $schedules): int
    {
        $raised = $schedules->raiseDue(Carbon::parse($this->option('date') ?? today()));

        foreach ($raised as $workOrder) {
            $this->line("{$workOrder->number} {$workOrder->property->name}: {$workOrder->title}, due {$workOrder->due_on->format('j M Y')}");
        }

        $this->info($raised->count().' work order(s) raised.');

        return self::SUCCESS;
    }
}
