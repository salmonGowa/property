<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('billing:run')->dailyAt('06:00')->withoutOverlapping();
Schedule::command('arrears:run')->dailyAt('06:30')->withoutOverlapping();
Schedule::command('maintenance:run')->dailyAt('07:00')->withoutOverlapping();
Schedule::command('loans:accrue-interest')->monthlyOn(1, '05:00')->withoutOverlapping();
Schedule::command('loans:test-covenants')->monthlyOn(1, '05:30')->withoutOverlapping();
