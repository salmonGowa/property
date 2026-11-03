<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->auditAuthenticationEvents();
    }

    private function auditAuthenticationEvents(): void
    {
        Event::listen(function (Login $event): void {
            app(AuditLogger::class)->record('auth.login', $event->user instanceof User ? $event->user : null);
        });

        Event::listen(function (Logout $event): void {
            if ($event->user instanceof User) {
                app(AuditLogger::class)->record('auth.logout', $event->user);
            }
        });

        Event::listen(function (Failed $event): void {
            app(AuditLogger::class)->record('auth.failed', null, null, ['email' => $event->credentials['email'] ?? null]);
        });
    }
}
