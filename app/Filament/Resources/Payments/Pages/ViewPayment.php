<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Payment;
use App\Services\Collections\PaymentService;
use App\Services\Ledger\InvalidJournalEntry;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    public function getTitle(): string
    {
        return "Receipt {$this->getRecord()->number}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reverse')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('danger')
                ->authorize('reverse')
                ->modalDescription('Use for bounced cheques, failed transfers or receipts entered in error. The invoices this payment settled become outstanding again.')
                ->schema([
                    DatePicker::make('date')->label('Reversal date')->default(today())->required(),
                    TextInput::make('reason')->required()->maxLength(200)->placeholder('e.g. Cheque returned unpaid'),
                ])
                ->action(function (array $data, Payment $record, PaymentService $payments): void {
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
