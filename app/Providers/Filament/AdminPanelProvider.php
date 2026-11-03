<?php

namespace App\Providers\Filament;

use App\Http\Controllers\InvoicePrintController;
use App\Http\Controllers\PurchaseOrderPrintController;
use App\Http\Controllers\SupplierPaymentPrintController;
use App\Http\Controllers\TenantStatementController;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->navigationGroups([
                'Property Management',
                'Maintenance',
                'Billing & Collections',
                'Procurement & Payables',
                'Debt & Financing',
                'Accounting',
                'Administration',
            ])
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->sidebarCollapsibleOnDesktop()
            ->authenticatedRoutes(function (): void {
                Route::get('tenants/{tenant}/statement', TenantStatementController::class)->name('statements.tenant');
                Route::get('invoices/{invoice}/print', InvoicePrintController::class)->name('invoices.print');
                Route::get('purchase-orders/{purchaseOrder}/print', PurchaseOrderPrintController::class)->name('purchase-orders.print');
                Route::get('supplier-payments/{supplierPayment}/print', SupplierPaymentPrintController::class)->name('supplier-payments.print');
            })
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
