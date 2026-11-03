<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Models\PurchaseOrder;
use App\Models\SupplierBill;
use App\Services\Procurement\ProcurementActionRefused;
use App\Services\Procurement\PurchaseOrderService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    public function getTitle(): string
    {
        return "Purchase order {$this->getRecord()->number}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('submit')
                ->label('Submit for approval')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->authorize('submit')
                ->requiresConfirmation()
                ->modalDescription(fn (PurchaseOrder $record): string => PurchaseOrderResource::approversFor((float) $record->total, $record->requested_by))
                ->action(fn () => $this->attempt(fn (PurchaseOrder $record, PurchaseOrderService $orders) => $orders->submit($record), 'Submitted for approval')),
            Action::make('approve')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->authorize('approve')
                ->requiresConfirmation()
                ->modalHeading(fn (PurchaseOrder $record): string => "Approve {$record->number}?")
                ->modalDescription(fn (PurchaseOrder $record): string => 'Commits KES '.Number::format((float) $record->total, 2)." with {$record->vendor->name}. The supplier can then bill up to this amount.")
                ->action(fn () => $this->attempt(fn (PurchaseOrder $record, PurchaseOrderService $orders) => $orders->approve($record, Auth::user()), 'Purchase order approved')),
            Action::make('reject')
                ->label('Return for changes')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('danger')
                ->authorize('approve')
                ->modalDescription('The order goes back to the requester as a draft, with your reason.')
                ->schema([TextInput::make('reason')->required()->maxLength(255)->placeholder('e.g. Get a second quote')])
                ->action(fn (array $data) => $this->attempt(fn (PurchaseOrder $record, PurchaseOrderService $orders) => $orders->reject($record, Auth::user(), $data['reason']), 'Returned to the requester')),
            Action::make('recordBill')
                ->label('Record supplier bill')
                ->icon(Heroicon::OutlinedDocumentText)
                ->visible(fn (PurchaseOrder $record): bool => $record->status === PurchaseOrderStatus::Approved)
                ->authorize(fn (): bool => Gate::allows('create', SupplierBill::class))
                ->url(fn (PurchaseOrder $record): string => SupplierBillResource::getUrl('create', ['purchase_order' => $record->id])),
            Action::make('print')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->visible(fn (PurchaseOrder $record): bool => in_array($record->status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Closed], true))
                ->url(fn (PurchaseOrder $record): string => route('filament.admin.purchase-orders.print', $record))
                ->openUrlInNewTab(),
            EditAction::make(),
            ActionGroup::make([
                Action::make('close')
                    ->label('Close order')
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->authorize('close')
                    ->modalDescription('Stops any further billing against this order, e.g. when the job came in under budget.')
                    ->schema([TextInput::make('reason')->required()->maxLength(255)->default('Work complete')])
                    ->action(fn (array $data) => $this->attempt(fn (PurchaseOrder $record, PurchaseOrderService $orders) => $orders->close($record, $data['reason']), 'Purchase order closed')),
                Action::make('cancel')
                    ->label('Cancel order')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->authorize('cancel')
                    ->schema([TextInput::make('reason')->required()->maxLength(255)])
                    ->action(fn (array $data) => $this->attempt(fn (PurchaseOrder $record, PurchaseOrderService $orders) => $orders->cancel($record, $data['reason']), 'Purchase order cancelled')),
            ]),
        ];
    }

    /**
     * Run a workflow step, reporting a refusal instead of failing.
     */
    private function attempt(Closure $step, string $success): void
    {
        try {
            $step($this->getRecord(), app(PurchaseOrderService::class));
        } catch (ProcurementActionRefused $exception) {
            Notification::make()->title('Not done')->body($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($success)->success()->send();
        $this->getRecord()->refresh();
        $this->refreshFormData(['status', 'rejection_reason', 'close_reason', 'approved_by']);
    }
}
