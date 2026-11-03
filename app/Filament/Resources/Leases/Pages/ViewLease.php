<?php

namespace App\Filament\Resources\Leases\Pages;

use App\Enums\PaymentMethod;
use App\Filament\Resources\Leases\LeaseResource;
use App\Models\Lease;
use App\Services\Leasing\DepositService;
use App\Services\Leasing\LeaseActionRefused;
use App\Services\Leasing\LeaseService;
use App\Services\Leasing\RentSchedule;
use App\Services\Ledger\InvalidJournalEntry;
use App\Services\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;

class ViewLease extends ViewRecord
{
    protected static string $resource = LeaseResource::class;

    public function getSubheading(): ?string
    {
        /** @var Lease $lease */
        $lease = $this->getRecord();

        return "{$lease->tenant->name} - {$lease->unit->fullCode()}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('activate')
                ->icon(Heroicon::OutlinedPlay)
                ->color('success')
                ->authorize('activate')
                ->requiresConfirmation()
                ->modalHeading('Activate lease')
                ->modalDescription(fn (Lease $record): string => 'This marks the unit as occupied'
                    .((float) $record->deposit_amount > 0 ? ', invoices the KES '.Number::format((float) $record->deposit_amount, 2).' deposit' : '')
                    .' and bills any rent already due. Lease terms can no longer be edited afterwards.')
                ->action(function (Lease $record, LeaseService $leases): void {
                    try {
                        $leases->activate($record);
                    } catch (LeaseActionRefused|InvalidJournalEntry $exception) {
                        Notification::make()->title('Lease not activated')->body($exception->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()->title('Lease activated')->body($record->invoices()->count().' invoice(s) issued.')->success()->send();
                    $this->refreshFormData(['status']);
                }),
            Action::make('renew')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('primary')
                ->authorize('renew')
                ->modalDescription('Creates a draft lease for the same tenant and unit. Activating it ends this lease the day before the new one starts.')
                ->fillForm(fn (Lease $record, RentSchedule $schedule): array => [
                    'start_date' => ($record->end_date ?? today())->copy()->addDay(),
                    'end_date' => ($record->end_date ?? today())->copy()->addYear(),
                    'rent_amount' => $schedule->monthlyRentOn($record, ($record->end_date ?? today())->copy()->addDay()),
                ])
                ->schema([
                    DatePicker::make('start_date')->required(),
                    DatePicker::make('end_date')->after('start_date'),
                    TextInput::make('rent_amount')->label('New monthly rent')->numeric()->minValue(0)->prefix('KES')->required(),
                ])
                ->action(function (array $data, Lease $record, LeaseService $leases): void {
                    $renewal = $leases->renew($record, $data['start_date'], $data['end_date'] ?? null, (string) $data['rent_amount']);

                    Notification::make()->title("Renewal {$renewal->reference} drafted")->success()->send();
                    $this->redirect(LeaseResource::getUrl('view', ['record' => $renewal]));
                }),
            Action::make('settleDeposit')
                ->label('Settle deposit')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('success')
                ->authorize('settleDeposit')
                ->visible(fn (Lease $record, DepositService $deposits): bool => Gate::allows('settleDeposit', $record) && Money::isPositive($deposits->heldFor($record)))
                ->modalWidth('2xl')
                ->modalDescription(fn (Lease $record, DepositService $deposits): string => 'Deposit held: KES '.Number::format((float) $deposits->heldFor($record), 2)
                    .'. Tenant currently owes KES '.Number::format(max(0, (float) $record->tenant->balance()), 2)
                    .'. Charges below are billed first, everything owed is paid from the deposit, and the remainder is refunded.')
                ->schema([
                    DatePicker::make('date')->label('Settlement date')->default(today())->required()->maxDate(today()),
                    Repeater::make('deductions')
                        ->label('Move-out charges (optional)')
                        ->defaultItems(0)
                        ->addActionLabel('Add charge')
                        ->table([TableColumn::make('Description'), TableColumn::make('Amount (KES)')])
                        ->schema([
                            TextInput::make('description')->required()->placeholder('e.g. Repaint bedroom, replace lock'),
                            TextInput::make('amount')->numeric()->minValue(0.01)->required(),
                        ]),
                    Select::make('refund_method')
                        ->label('Refund the balance by')
                        ->options(collect([PaymentMethod::Mpesa, PaymentMethod::BankTransfer, PaymentMethod::Cheque, PaymentMethod::Cash])->mapWithKeys(fn (PaymentMethod $method): array => [$method->value => $method->getLabel()])->all())
                        ->placeholder('Do not refund yet')
                        ->live(),
                    TextInput::make('refund_reference')
                        ->label('Refund reference')
                        ->placeholder('M-Pesa code, cheque or EFT number')
                        ->visible(fn (Get $get): bool => filled($get('refund_method'))),
                ])
                ->action(function (array $data, Lease $record, DepositService $deposits): void {
                    try {
                        $settlement = $deposits->settle(
                            $record,
                            $data['date'],
                            array_values($data['deductions'] ?? []),
                            filled($data['refund_method'] ?? null) ? PaymentMethod::from($data['refund_method']) : null,
                            $data['refund_reference'] ?? null,
                        );
                    } catch (LeaseActionRefused|InvalidJournalEntry $exception) {
                        Notification::make()->title('Deposit not settled')->body($exception->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Deposit settled')
                        ->body('Applied to balance: KES '.Number::format((float) $settlement->applied, 2)
                            .'. Refunded: KES '.Number::format((float) $settlement->refunded, 2)
                            .(Money::isPositive($settlement->stillHeld) ? '. Still held for refund: KES '.Number::format((float) $settlement->stillHeld, 2) : '').'.')
                        ->success()
                        ->persistent()
                        ->send();
                }),
            EditAction::make(),
            ActionGroup::make([
                Action::make('changePenaltyRule')
                    ->label('Change penalty rule')
                    ->icon(Heroicon::OutlinedScale)
                    ->authorize('changePenaltyRule')
                    ->fillForm(fn (Lease $record): array => $record->only(['penalty_rule_id', 'penalties_exempt']))
                    ->schema(LeaseResource::penaltyFields())
                    ->action(function (array $data, Lease $record): void {
                        $record->update([
                            'penalty_rule_id' => $data['penalties_exempt'] ? null : ($data['penalty_rule_id'] ?? null),
                            'penalties_exempt' => (bool) $data['penalties_exempt'],
                        ]);

                        Notification::make()->title('Penalty rule updated')->body($record->refresh()->effectivePenaltyRule()['source'])->success()->send();
                    }),
                Action::make('terminate')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->authorize('terminate')
                    ->modalDescription('The unit becomes vacant. Rent already billed for days after the termination date is credited back automatically (or voided if unpaid).')
                    ->schema([
                        DatePicker::make('terminated_on')->label('Last day of occupation')->required()->default(today()),
                        TextInput::make('reason')->required()->maxLength(255)->placeholder('e.g. Tenant gave notice, eviction, mutual agreement'),
                    ])
                    ->action(function (array $data, Lease $record, LeaseService $leases): void {
                        try {
                            $adjustments = $leases->terminate($record, $data['terminated_on'], $data['reason']);
                        } catch (LeaseActionRefused|InvalidJournalEntry $exception) {
                            Notification::make()->title('Lease not terminated')->body($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Lease terminated')
                            ->body(($adjustments === [] ? '' : count($adjustments).' invoice(s) credited or voided for days after the termination date. ').'Next: settle the deposit.')
                            ->success()
                            ->send();
                        $this->refreshFormData(['status', 'terminated_on', 'termination_reason']);
                    }),
            ]),
        ];
    }
}
