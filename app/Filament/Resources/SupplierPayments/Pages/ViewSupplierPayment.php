<?php

namespace App\Filament\Resources\SupplierPayments\Pages;

use App\Filament\Resources\SupplierPayments\SupplierPaymentResource;
use App\Models\SupplierPayment;
use App\Services\Ledger\InvalidJournalEntry;
use App\Services\Procurement\SupplierPaymentService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ViewSupplierPayment extends ViewRecord
{
    protected static string $resource = SupplierPaymentResource::class;

    public function getTitle(): string
    {
        return "Payment {$this->getRecord()->number}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Print voucher')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn (SupplierPayment $record): string => route('filament.admin.supplier-payments.print', $record))
                ->openUrlInNewTab(),
            Action::make('reverse')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('danger')
                ->authorize('reverse')
                ->modalDescription('Use for stopped cheques, failed transfers or payments entered in error. The bills it settled become unpaid again.')
                ->schema([
                    DatePicker::make('date')->label('Reversal date')->default(today())->required(),
                    TextInput::make('reason')->required()->maxLength(200),
                ])
                ->action(function (array $data, SupplierPayment $record, SupplierPaymentService $payments): void {
                    try {
                        $payments->reverse($record, $data['reason'], Auth::user(), $data['date']);
                    } catch (InvalidJournalEntry $exception) {
                        Notification::make()->title('Not reversed')->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title("{$record->number} reversed")->success()->send();
                    $this->refreshFormData(['status', 'reversed_at', 'reversal_reason']);
                }),
        ];
    }
}
