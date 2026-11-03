<?php

namespace App\Services\Maintenance;

use App\Models\MaintenanceSchedule;
use App\Models\WorkOrder;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Raises work orders for preventive maintenance as each schedule falls due.
 */
class MaintenanceScheduleService
{
    public function __construct(private WorkOrderService $workOrders) {}

    /**
     * Raise one work order per schedule whose next visit is within its lead time, then move the
     * schedule on to its following visit. Missed visits are not raised twice.
     *
     * @return Collection<int, WorkOrder>
     */
    public function raiseDue(CarbonInterface|string $on): Collection
    {
        $on = Carbon::parse($on)->startOfDay();

        return MaintenanceSchedule::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (MaintenanceSchedule $schedule): bool => $this->isDue($schedule, $on))
            ->map(fn (MaintenanceSchedule $schedule): WorkOrder => DB::transaction(function () use ($schedule, $on): WorkOrder {
                $workOrder = $this->workOrders->log([
                    'property_id' => $schedule->property_id,
                    'unit_id' => $schedule->unit_id,
                    'title' => $schedule->title,
                    'description' => $schedule->description,
                    'category' => $schedule->category,
                    'priority' => $schedule->priority,
                    'vendor_id' => $schedule->vendor_id,
                    'reported_at' => $on,
                    'due_on' => $schedule->next_due_on,
                    'maintenance_schedule_id' => $schedule->id,
                ]);

                $next = $schedule->next_due_on->copy();

                while ($this->isDue($schedule, $on, $next)) {
                    $next = $next->addMonthsNoOverflow($schedule->frequency->months());
                }

                $schedule->update(['next_due_on' => $next, 'last_raised_on' => $on]);

                return $workOrder;
            }))
            ->values();
    }

    private function isDue(MaintenanceSchedule $schedule, CarbonInterface $on, ?CarbonInterface $visit = null): bool
    {
        return ($visit ?? $schedule->next_due_on)->copy()->subDays($schedule->lead_days)->lte($on);
    }
}
