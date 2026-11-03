<?php

namespace App\Console\Commands;

use App\Services\Billing\BillingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

#[Signature('billing:run {--date= : Bill periods starting on or before this date (default: today)}')]
#[Description('Invoice rent and service charge for every active lease period that is due and not yet billed')]
class RunBilling extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BillingService $billing): int
    {
        $upTo = Carbon::parse($this->option('date') ?? today());
        $result = $billing->run($upTo);

        $this->info(count($result->invoices).' invoice(s) issued totalling KES '.Number::format((float) $result->total(), 2).'.');

        foreach ($result->failures as $lease => $message) {
            $this->warn("{$lease}: {$message}");
        }

        return $result->failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
