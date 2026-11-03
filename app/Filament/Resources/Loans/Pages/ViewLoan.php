<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Enums\InterestRateType;
use App\Enums\LoanStatus;
use App\Filament\Resources\Loans\LoanResource;
use App\Models\Account;
use App\Models\Loan;
use App\Services\Debt\CovenantService;
use App\Services\Debt\LoanActionRefused;
use App\Services\Debt\LoanService;
use App\Services\Ledger\InvalidJournalEntry;
use App\Services\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Number;

class ViewLoan extends ViewRecord
{
    protected static string $resource = LoanResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->name;
    }

    public function getSubheading(): ?string
    {
        return "{$this->getRecord()->reference} · {$this->getRecord()->lender->name}";
    }

    protected function getHeaderActions(): array
    {
        $bankAccount = fn (): Select => Select::make('bank_account_id')
            ->label('Bank account')
            ->options(fn (): array => LoanResource::bankAccountOptions())
            ->default(fn (Loan $record): int => $record->bank_account_id)
            ->required();

        return [
            Action::make('payInstalment')
                ->label('Pay instalment')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('success')
                ->authorize('transact')
                ->visible(fn (Loan $record): bool => $record->nextInstalment !== null)
                ->modalHeading(fn (Loan $record): string => "Pay instalment {$record->nextInstalment->sequence}, due {$record->nextInstalment->due_date->format('j M Y')}")
                ->modalDescription('Amounts come from the schedule. If the bank charged different figures, enter theirs; the remaining schedule is recalculated.')
                ->fillForm(fn (Loan $record): array => [
                    'principal' => $record->nextInstalment->principal,
                    'interest' => $record->nextInstalment->interest,
                    'paid_on' => $record->nextInstalment->due_date->min(today()),
                    'bank_account_id' => $record->bank_account_id,
                ])
                ->schema([
                    TextInput::make('principal')->numeric()->minValue(0)->rule('decimal:0,2')->prefix('KES')->required()->live(onBlur: true),
                    TextInput::make('interest')->numeric()->minValue(0)->rule('decimal:0,2')->prefix('KES')->required()->live(onBlur: true),
                    Text::make(function (Get $get, Loan $record): string {
                        $interest = (float) $get('interest');
                        $withheld = round($interest * (float) $record->lender->interest_withholding_rate / 100, 2);

                        return 'Paid to the bank: KES '.Number::format((float) $get('principal') + $interest - $withheld, 2)
                            .($withheld > 0 ? ' (after withholding KES '.Number::format($withheld, 2).' tax on interest for KRA)' : '');
                    })->color('gray'),
                    DatePicker::make('paid_on')->label('Date paid')->maxDate(today())->required(),
                    $bankAccount(),
                    TextInput::make('reference')->label('Bank reference')->maxLength(100),
                ])
                ->action(fn (array $data) => $this->attempt(fn (Loan $record, LoanService $loans) => $loans->payInstalment(
                    $record->nextInstalment,
                    $data['paid_on'],
                    (string) $data['principal'],
                    (string) $data['interest'],
                    Account::query()->find($data['bank_account_id']),
                    $data['reference'] ?? null,
                ), 'Instalment paid')),
            Action::make('drawdown')
                ->label('Record drawdown')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color(fn (Loan $record): string => $record->status === LoanStatus::Pending ? 'primary' : 'gray')
                ->authorize('transact')
                ->visible(fn (Loan $record): bool => Money::isPositive($record->undrawnAmount()))
                ->modalDescription(fn (Loan $record): string => 'KES '.Number::format((float) $record->undrawnAmount(), 2).' of the facility is available. The schedule is rebuilt after each drawdown.')
                ->fillForm(fn (Loan $record): array => ['amount' => $record->undrawnAmount(), 'drawn_on' => today(), 'fees' => 0, 'bank_account_id' => $record->bank_account_id])
                ->schema([
                    TextInput::make('amount')->numeric()->minValue(0.01)->rule('decimal:0,2')->prefix('KES')->required(),
                    TextInput::make('fees')->label('Arrangement fees deducted by the lender')->numeric()->minValue(0)->rule('decimal:0,2')->prefix('KES'),
                    DatePicker::make('drawn_on')->label('Date received')->maxDate(today())->required(),
                    $bankAccount(),
                    TextInput::make('reference')->maxLength(100),
                ])
                ->action(fn (array $data) => $this->attempt(fn (Loan $record, LoanService $loans) => $loans->drawdown(
                    $record,
                    (string) $data['amount'],
                    $data['drawn_on'],
                    (string) ($data['fees'] ?: '0'),
                    Account::query()->find($data['bank_account_id']),
                    $data['reference'] ?? null,
                ), 'Drawdown recorded')),
            ActionGroup::make([
                Action::make('prepay')
                    ->label('Prepay principal')
                    ->icon(Heroicon::OutlinedForward)
                    ->authorize('transact')
                    ->visible(fn (Loan $record): bool => $record->status === LoanStatus::Active)
                    ->modalDescription('Repays principal early. The remaining instalments are recalculated over the same term, so they fall. Pay any interest or penalty the bank charges on the next instalment.')
                    ->fillForm(fn (Loan $record): array => ['paid_on' => today(), 'bank_account_id' => $record->bank_account_id])
                    ->schema([
                        TextInput::make('amount')->numeric()->minValue(0.01)->rule('decimal:0,2')->prefix('KES')->required()
                            ->helperText(fn (Loan $record): string => 'KES '.Number::format((float) $record->outstandingPrincipal(), 2).' outstanding.'),
                        DatePicker::make('paid_on')->label('Date paid')->maxDate(today())->required(),
                        $bankAccount(),
                        TextInput::make('reference')->maxLength(100),
                    ])
                    ->action(fn (array $data) => $this->attempt(fn (Loan $record, LoanService $loans) => $loans->prepay(
                        $record,
                        (string) $data['amount'],
                        $data['paid_on'],
                        Account::query()->find($data['bank_account_id']),
                        $data['reference'] ?? null,
                    ), 'Prepayment recorded')),
                Action::make('changeRate')
                    ->label('Change interest rate')
                    ->icon(Heroicon::OutlinedArrowTrendingUp)
                    ->authorize('transact')
                    ->modalDescription(fn (Loan $record): string => "Now {$record->rateDescription()}. The new rate applies from the next unpaid instalment.")
                    ->fillForm(fn (Loan $record): array => ['effective_from' => today(), 'base_rate' => $record->base_rate, 'margin' => $record->margin, 'interest_rate' => $record->interest_rate])
                    ->schema(fn (Loan $record): array => [
                        DatePicker::make('effective_from')->required(),
                        ...($record->rate_type === InterestRateType::Variable ? [
                            TextInput::make('base_rate')->label(($record->base_rate_name ?: 'Base rate').' now')->numeric()->suffix('%')->required(),
                            TextInput::make('margin')->numeric()->suffix('%')->required(),
                        ] : [
                            TextInput::make('interest_rate')->label('New annual rate')->numeric()->suffix('%')->required(),
                        ]),
                        TextInput::make('reason')->maxLength(255)->placeholder('e.g. CBK Monetary Policy Committee decision'),
                    ])
                    ->action(fn (array $data) => $this->attempt(fn (Loan $record, LoanService $loans) => $loans->changeRate(
                        $record,
                        $data['effective_from'],
                        isset($data['interest_rate']) ? (string) $data['interest_rate'] : null,
                        isset($data['base_rate']) ? (string) $data['base_rate'] : null,
                        isset($data['margin']) ? (string) $data['margin'] : null,
                        $data['reason'] ?? null,
                    ), 'Interest rate updated')),
                Action::make('testCovenants')
                    ->label('Record covenant test')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->authorize('transact')
                    ->visible(fn (Loan $record): bool => $record->status === LoanStatus::Active && $record->covenants()->where('is_active', true)->exists())
                    ->requiresConfirmation()
                    ->modalDescription('Measures each covenant as at today and keeps the result, e.g. for the lender\'s compliance certificate. This also runs on the 1st of every month.')
                    ->action(function (Loan $record, CovenantService $covenants): void {
                        $inputs = $covenants->inputs($record, today());
                        $tests = $record->covenants()->where('is_active', true)->get()->map(fn ($covenant) => $covenants->record($covenant, today(), $inputs));
                        $breached = $tests->where('passed', false)->count();

                        Notification::make()
                            ->title($breached > 0 ? "{$breached} covenant(s) breached" : 'Covenants recorded')
                            ->body($tests->map(fn ($test): string => $test->covenant->metric->shortLabel().' '.$test->covenant->metric->format($test->actual))->implode(' · '))
                            ->status($breached > 0 ? 'danger' : 'success')
                            ->send();
                    }),
                EditAction::make(),
            ]),
        ];
    }

    /**
     * Run a loan transaction, reporting a refusal instead of failing.
     */
    private function attempt(Closure $step, string $success): void
    {
        try {
            $step($this->getRecord(), app(LoanService::class));
        } catch (LoanActionRefused|InvalidJournalEntry $exception) {
            Notification::make()->title('Not recorded')->body($exception->getMessage())->danger()->persistent()->send();

            return;
        }

        Notification::make()->title($success)->success()->send();
        $this->getRecord()->refresh();
        $this->refreshFormData(['status', 'interest_rate', 'base_rate', 'margin']);
    }
}
