<?php

namespace App\Filament\Resources\MpesaTransactions;

use App\Enums\MpesaTransactionStatus;
use App\Filament\Resources\MpesaTransactions\Pages\ManageMpesaTransactions;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\MpesaTransaction;
use App\Models\Tenant;
use App\Services\Collections\MpesaC2bService;
use App\Services\Ledger\InvalidJournalEntry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Number;
use UnitEnum;

class MpesaTransactionResource extends Resource
{
    protected static ?string $model = MpesaTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'Billing & Collections';

    protected static ?string $navigationLabel = 'M-Pesa Inbox';

    protected static ?string $modelLabel = 'M-Pesa transaction';

    protected static ?int $navigationSort = 3;

    public static function getNavigationBadge(): ?string
    {
        $unmatched = MpesaTransaction::query()->where('status', MpesaTransactionStatus::Unmatched)->count();

        return $unmatched > 0 ? (string) $unmatched : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Paybill payments waiting to be matched to a tenant';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('payment.tenant'))
            ->defaultSort('transacted_at', 'desc')
            ->columns([
                TextColumn::make('transacted_at')->label('Received')->dateTime('j M Y H:i')->sortable(),
                TextColumn::make('trans_id')->label('M-Pesa code')->fontFamily(FontFamily::Mono)->searchable()->copyable(),
                TextColumn::make('payer_name')
                    ->label('Paid by')
                    ->description(fn (MpesaTransaction $record): ?string => $record->msisdn)
                    ->searchable(['payer_name', 'msisdn']),
                TextColumn::make('bill_ref_number')
                    ->label('Account quoted')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('amount')->money('KES')->alignEnd()->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('payment.number')
                    ->label('Receipt')
                    ->description(fn (MpesaTransaction $record): ?string => $record->payment?->tenant->name)
                    ->url(fn (MpesaTransaction $record): ?string => $record->payment_id ? PaymentResource::getUrl('view', ['record' => $record->payment_id]) : null)
                    ->placeholder('-'),
            ])
            ->recordActions([
                Action::make('match')
                    ->label('Match to tenant')
                    ->icon(Heroicon::OutlinedLink)
                    ->color('primary')
                    ->authorize('match')
                    ->modalDescription(fn (MpesaTransaction $record): string => 'KES '.Number::format((float) $record->amount, 2)." from {$record->payer_name} ({$record->msisdn}), account quoted: ".($record->bill_ref_number ?: 'none').'.')
                    ->schema([
                        Select::make('tenant_id')
                            ->label('Tenant')
                            ->options(fn (): array => Tenant::query()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn (Tenant $tenant): array => [$tenant->id => "{$tenant->name} ({$tenant->account_number}, {$tenant->phone})"])->all())
                            ->default(fn (MpesaTransaction $record): ?int => self::suggestTenant($record)?->id)
                            ->searchable()
                            ->required()
                            ->helperText('Suggested from the phone number when it matches a tenant.'),
                        Text::make('A receipt is created and applied to the tenant\'s oldest invoices.')->color('gray'),
                    ])
                    ->action(function (array $data, MpesaTransaction $record, MpesaC2bService $mpesa): void {
                        try {
                            $mpesa->match($record, Tenant::query()->findOrFail($data['tenant_id']));
                        } catch (InvalidJournalEntry $exception) {
                            Notification::make()->title('Not matched')->body($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Payment receipted')->success()->send();
                    }),
                Action::make('ignore')
                    ->icon(Heroicon::OutlinedEyeSlash)
                    ->color('gray')
                    ->authorize('ignore')
                    ->requiresConfirmation()
                    ->modalDescription('Use for payments that are not rent, e.g. paid to the wrong Paybill and refunded. Ignored transactions can still be matched later.')
                    ->action(fn (MpesaTransaction $record) => $record->update(['status' => MpesaTransactionStatus::Ignored])),
            ])
            ->emptyStateHeading('No M-Pesa payments yet')
            ->emptyStateDescription('Paybill payments appear here as Safaricom confirms them.');
    }

    /**
     * Guess the payer from the paying phone number (Safaricom may mask middle digits).
     */
    private static function suggestTenant(MpesaTransaction $transaction): ?Tenant
    {
        if (blank($transaction->msisdn) || str_contains($transaction->msisdn, '*') || str_contains(strtoupper($transaction->msisdn), 'X')) {
            return null;
        }

        return Tenant::query()->where('phone', '+'.ltrim($transaction->msisdn, '+'))->first();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMpesaTransactions::route('/'),
        ];
    }
}
