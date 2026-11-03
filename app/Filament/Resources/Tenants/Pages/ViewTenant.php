<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Leases\LeaseResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\RepaymentPlans\RepaymentPlanResource;
use App\Filament\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewTenant extends ViewRecord
{
    protected static string $resource = TenantResource::class;

    public function getSubheading(): ?string
    {
        /** @var Tenant $tenant */
        $tenant = $this->getRecord();

        return "Account {$tenant->account_number}".($tenant->is_active ? '' : ' - inactive');
    }

    protected function getHeaderActions(): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->getRecord();

        return [
            PaymentResource::recordPaymentAction($tenant)
                ->after(fn () => $this->dispatch('$refresh')),
            Action::make('statement')
                ->icon(Heroicon::OutlinedDocumentChartBar)
                ->color('gray')
                ->modalDescription('Opens a printable statement in a new tab.')
                ->modalSubmitActionLabel('Open statement')
                ->schema([
                    DatePicker::make('from')->default(today()->subMonths(11)->startOfMonth())->required(),
                    DatePicker::make('to')->default(today())->required()->afterOrEqual('from'),
                ])
                ->action(fn (array $data) => $this->js('window.open('.json_encode(route('filament.admin.statements.tenant', ['tenant' => $this->getRecord(), 'from' => $data['from'], 'to' => $data['to']])).', "_blank")')),
            EditAction::make(),
            ActionGroup::make([
                RepaymentPlanResource::agreeAction($tenant),
                Action::make('newLease')
                    ->label('New lease')
                    ->icon(Heroicon::OutlinedDocumentPlus)
                    ->visible(LeaseResource::canCreate())
                    ->url(LeaseResource::getUrl('create', ['tenant' => $tenant->id])),
                Action::make('newInvoice')
                    ->label('New invoice')
                    ->icon(Heroicon::OutlinedDocumentCurrencyDollar)
                    ->visible(InvoiceResource::canCreate())
                    ->url(InvoiceResource::getUrl('create', ['tenant' => $tenant->id])),
            ]),
        ];
    }
}
