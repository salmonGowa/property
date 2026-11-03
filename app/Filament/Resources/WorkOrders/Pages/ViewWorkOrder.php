<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Enums\WorkOrderStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Filament\Resources\Vendors\VendorResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\PurchaseOrder;
use App\Models\SupplierBill;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\Ledger\InvalidJournalEntry;
use App\Services\Maintenance\WorkOrderService;
use App\Services\Procurement\ProcurementActionRefused;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

class ViewWorkOrder extends ViewRecord
{
    protected static string $resource = WorkOrderResource::class;

    public function getTitle(): string
    {
        return "Work order {$this->getRecord()->number}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('assign')
                ->label(fn (WorkOrder $record): string => $record->vendor_id ? 'Reassign' : 'Assign supplier')
                ->icon(Heroicon::OutlinedUserPlus)
                ->color(fn (WorkOrder $record): string => $record->vendor_id ? 'gray' : 'primary')
                ->authorize('update')
                ->fillForm(fn (WorkOrder $record): array => ['vendor_id' => $record->vendor_id, 'estimated_cost' => $record->estimated_cost])
                ->schema([
                    Select::make('vendor_id')
                        ->label('Supplier')
                        ->options(fn (WorkOrder $record): array => VendorResource::options($record->category->value))
                        ->searchable()
                        ->required(),
                    TextInput::make('estimated_cost')->numeric()->minValue(0)->rule('decimal:0,2')->prefix('KES'),
                ])
                ->action(fn (array $data) => $this->attempt(fn (WorkOrder $record, WorkOrderService $workOrders) => $workOrders->assign($record, Vendor::query()->findOrFail($data['vendor_id']), filled($data['estimated_cost']) ? (string) $data['estimated_cost'] : null), 'Supplier assigned')),
            Action::make('start')
                ->label('Start work')
                ->icon(Heroicon::OutlinedPlay)
                ->color('warning')
                ->authorize('update')
                ->visible(fn (WorkOrder $record): bool => in_array($record->status, [WorkOrderStatus::Open, WorkOrderStatus::Assigned], true))
                ->action(fn () => $this->attempt(fn (WorkOrder $record, WorkOrderService $workOrders) => $workOrders->start($record), 'Work started')),
            Action::make('complete')
                ->label('Mark completed')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->authorize('update')
                ->schema([
                    Textarea::make('resolution')->label('What was done')->required()->rows(3),
                    DateTimePicker::make('completed_at')->label('Completed')->default(now())->maxDate(now())->seconds(false)->required(),
                ])
                ->action(fn (array $data) => $this->attempt(fn (WorkOrder $record, WorkOrderService $workOrders) => $workOrders->complete($record, $data['resolution'], $data['completed_at']), 'Work order completed')),
            Action::make('recharge')
                ->label('Recharge tenant')
                ->icon(Heroicon::OutlinedReceiptPercent)
                ->color('warning')
                ->authorize('recharge')
                ->modalDescription(fn (WorkOrder $record): string => "Invoices {$record->tenant?->name} for the damage. The charge is posted to Other Income.")
                ->fillForm(fn (WorkOrder $record): array => ['amount' => bccomp($record->actualCost(), '0', 2) > 0 ? $record->actualCost() : $record->estimated_cost, 'due_date' => today()->addDays(14), 'vat' => false])
                ->schema([
                    TextInput::make('amount')->numeric()->minValue(0.01)->rule('decimal:0,2')->prefix('KES')->required()->helperText('Defaults to what the work cost before VAT.'),
                    Toggle::make('vat')->label('Charge VAT '.config('ledger.vat_rate').'%'),
                    DatePicker::make('due_date')->required()->minDate(today()),
                ])
                ->action(function (array $data, WorkOrder $record, WorkOrderService $workOrders): void {
                    try {
                        $invoice = $workOrders->rechargeTenant($record, (string) $data['amount'], $data['due_date'], (bool) $data['vat']);
                    } catch (ProcurementActionRefused|InvalidJournalEntry $exception) {
                        Notification::make()->title('Not recharged')->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title("Invoice {$invoice->number} issued to {$invoice->tenant->name}")
                        ->success()
                        ->actions([Action::make('view')->url(InvoiceResource::getUrl('view', ['record' => $invoice]))])
                        ->send();
                    $this->refreshFormData(['recharge_invoice_id']);
                }),
            ActionGroup::make([
                Action::make('raisePurchaseOrder')
                    ->label('Raise purchase order')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->visible(fn (WorkOrder $record): bool => $record->status->isUnfinished())
                    ->authorize(fn (): bool => Gate::allows('create', PurchaseOrder::class))
                    ->url(fn (WorkOrder $record): string => PurchaseOrderResource::getUrl('create', ['work_order' => $record->id])),
                Action::make('recordBill')
                    ->label('Record supplier bill')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->visible(fn (WorkOrder $record): bool => $record->status !== WorkOrderStatus::Cancelled)
                    ->authorize(fn (): bool => Gate::allows('create', SupplierBill::class))
                    ->url(fn (WorkOrder $record): string => SupplierBillResource::getUrl('create', ['work_order' => $record->id])),
                EditAction::make(),
                Action::make('cancel')
                    ->label('Cancel work order')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->authorize('cancel')
                    ->schema([TextInput::make('reason')->required()->maxLength(255)])
                    ->action(fn (array $data) => $this->attempt(fn (WorkOrder $record, WorkOrderService $workOrders) => $workOrders->cancel($record, $data['reason']), 'Work order cancelled')),
            ]),
        ];
    }

    /**
     * Run a workflow step, reporting a refusal instead of failing.
     */
    private function attempt(Closure $step, string $success): void
    {
        try {
            $step($this->getRecord(), app(WorkOrderService::class));
        } catch (ProcurementActionRefused $exception) {
            Notification::make()->title('Not done')->body($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($success)->success()->send();
        $this->getRecord()->refresh();
        $this->refreshFormData(['status', 'vendor_id', 'estimated_cost', 'assigned_at', 'started_at', 'completed_at', 'resolution', 'cancel_reason']);
    }
}
