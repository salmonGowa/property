<?php

namespace App\Filament\Resources\Tenants;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\TenantType;
use App\Filament\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Resources\Tenants\Pages\EditTenant;
use App\Filament\Resources\Tenants\Pages\ListTenants;
use App\Filament\Resources\Tenants\Pages\ViewTenant;
use App\Filament\Resources\Tenants\RelationManagers\CreditNotesRelationManager;
use App\Filament\Resources\Tenants\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Tenants\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\Tenants\RelationManagers\LeasesRelationManager;
use App\Filament\Resources\Tenants\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Tenants\RelationManagers\RepaymentPlansRelationManager;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Arrears\ArrearsAging;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use UnitEnum;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Property Management';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'account_number', 'phone', 'email', 'kra_pin'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Account' => $record->account_number, 'Phone' => $record->phone];
    }

    /**
     * The minimum needed to register a tenant; also used for quick-create from the lease wizard.
     *
     * @return list<Component>
     */
    public static function essentialFields(): array
    {
        $isCompany = fn (Get $get): bool => in_array($get('type'), [TenantType::Company, TenantType::Company->value], true);

        return [
            ToggleButtons::make('type')
                ->options(TenantType::class)
                ->icons([TenantType::Individual->value => Heroicon::OutlinedUser, TenantType::Company->value => Heroicon::OutlinedBuildingOffice])
                ->default(TenantType::Individual->value)
                ->inline()
                ->live()
                ->required()
                ->columnSpanFull(),
            TextInput::make('name')
                ->label(fn (Get $get): string => $isCompany($get) ? 'Company name' : 'Full name')
                ->required()
                ->maxLength(255),
            TextInput::make('phone')
                ->tel()
                ->required()
                ->placeholder('0712 345 678')
                ->regex('/^(\+?254|0)\s?[17]\d{2}\s?\d{3}\s?\d{3}$/')
                ->validationMessages(['regex' => 'Enter a Kenyan mobile number, e.g. 0712 345 678 or +254 712 345 678.'])
                ->dehydrateStateUsing(fn (?string $state): ?string => self::normalisePhone($state)),
            TextInput::make('email')
                ->email()
                ->maxLength(255),
            TextInput::make('kra_pin')
                ->label('KRA PIN')
                ->placeholder(fn (Get $get): string => $isCompany($get) ? 'P051234567X' : 'A001234567Z')
                ->regex('/^[AaPp]\d{9}[A-Za-z]$/')
                ->validationMessages(['regex' => 'A KRA PIN is a letter, nine digits and a letter, e.g. A001234567Z.'])
                ->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper(trim($state)) : null)
                ->helperText('Needed on invoices for eTIMS and withholding tax.'),
            TextInput::make('id_number')
                ->label('National ID / passport number')
                ->maxLength(20)
                ->visible(fn (Get $get): bool => ! $isCompany($get))
                ->helperText('Stored encrypted.'),
            TextInput::make('registration_number')
                ->label('Company registration number')
                ->maxLength(50)
                ->placeholder('PVT-AB1234')
                ->visible($isCompany),
            TextInput::make('contact_person')
                ->maxLength(255)
                ->visible($isCompany),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Identity')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema(self::essentialFields()),
                Section::make('Account')
                    ->columnSpan(1)
                    ->schema([
                        TextInput::make('account_number')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Assigned on save')
                            ->helperText('Tenants quote this as the M-Pesa Paybill account number.'),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),
                Section::make('Other contact details')
                    ->columnSpan(2)
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        TextInput::make('alternate_phone')->tel()->maxLength(20),
                        TextInput::make('contact_person_phone')->tel()->maxLength(20),
                        TextInput::make('postal_address')->maxLength(255)->placeholder('P.O. Box 12345-00100, Nairobi'),
                        TextInput::make('emergency_contact_name')->maxLength(255),
                        TextInput::make('emergency_contact_phone')->tel()->maxLength(20),
                    ]),
                Section::make('Notes')
                    ->columnSpan(1)
                    ->schema([
                        Textarea::make('notes')->hiddenLabel()->rows(6),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Grid::make(4)
                    ->columnSpanFull()
                    ->schema([
                        Section::make()->compact()->schema([
                            TextEntry::make('balance')
                                ->label(fn (Tenant $record): string => bccomp($record->balance(), '0', 2) < 0 ? 'In credit' : 'Balance due')
                                ->state(fn (Tenant $record): string => 'KES '.Number::format(abs((float) $record->balance()), 2))
                                ->color(fn (Tenant $record): string => match (bccomp($record->balance(), '0', 2)) {
                                    1 => 'danger',
                                    -1 => 'success',
                                    default => 'gray',
                                })
                                ->size('lg')
                                ->weight('bold'),
                        ]),
                        Section::make()->compact()->schema([
                            TextEntry::make('overdue')
                                ->label('Overdue')
                                ->state(fn (Tenant $record): string => 'KES '.Number::format((float) Invoice::query()->whereBelongsTo($record)->overdue()->get()->sum(fn (Invoice $invoice): float => (float) $invoice->balanceDue()), 2))
                                ->size('lg')
                                ->weight('bold'),
                        ]),
                        Section::make()->compact()->schema([
                            TextEntry::make('active_leases')
                                ->label('Active leases')
                                ->state(fn (Tenant $record): int => $record->activeLeases()->count())
                                ->size('lg')
                                ->weight('bold'),
                        ]),
                        Section::make()->compact()->schema([
                            TextEntry::make('last_payment')
                                ->label('Last payment')
                                ->state(function (Tenant $record): string {
                                    $payment = $record->payments()->where('status', PaymentStatus::Posted)->latest('received_on')->first();

                                    return $payment ? 'KES '.Number::format((float) $payment->amount, 2).' on '.$payment->received_on->format('j M Y') : 'None yet';
                                })
                                ->size('lg')
                                ->weight('bold'),
                        ]),
                    ]),
                Section::make('Arrears aging')
                    ->columnSpanFull()
                    ->columns(5)
                    ->compact()
                    ->visible(fn (Tenant $record): bool => bccomp($record->balance(), '0', 2) > 0)
                    ->schema(collect(ArrearsAging::BUCKETS)->map(fn (string $label, string $key): TextEntry => TextEntry::make("aging_{$key}")
                        ->label($label)
                        ->state(fn (Tenant $record): string => app(ArrearsAging::class)->forTenant($record)[$key])
                        ->money('KES')
                        ->weight('semibold')
                        ->color(fn (string $state): ?string => $key !== 'current' && bccomp($state, '0', 2) > 0 ? 'danger' : null))
                        ->values()
                        ->all()),
                Section::make('Tenant')
                    ->columnSpan(2)
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name')->weight('bold')->columnSpan(2),
                        TextEntry::make('type')->badge()->color('gray'),
                        TextEntry::make('phone')->url(fn (Tenant $record): string => "tel:{$record->phone}")->icon(Heroicon::OutlinedPhone),
                        TextEntry::make('email')->url(fn (Tenant $record): ?string => $record->email ? "mailto:{$record->email}" : null)->icon(Heroicon::OutlinedEnvelope)->placeholder('-'),
                        TextEntry::make('kra_pin')->label('KRA PIN')->fontFamily(FontFamily::Mono)->placeholder('Missing')->copyable(),
                        TextEntry::make('id_number')
                            ->label('ID / passport')
                            ->state(fn (Tenant $record): ?string => self::maskedIdNumber($record))
                            ->placeholder('-')
                            ->visible(fn (Tenant $record): bool => $record->type === TenantType::Individual),
                        TextEntry::make('registration_number')->label('Registration no.')->placeholder('-')->visible(fn (Tenant $record): bool => $record->type === TenantType::Company),
                        TextEntry::make('contact_person')->placeholder('-')->visible(fn (Tenant $record): bool => $record->type === TenantType::Company),
                        TextEntry::make('postal_address')->placeholder('-'),
                        TextEntry::make('emergency_contact_name')
                            ->label('Emergency contact')
                            ->formatStateUsing(fn (Tenant $record): string => trim("{$record->emergency_contact_name} {$record->emergency_contact_phone}"))
                            ->placeholder('-'),
                        TextEntry::make('notes')->placeholder('-')->columnSpanFull(),
                    ]),
                Section::make('Paying by M-Pesa')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('account_number')
                            ->label('Paybill account number')
                            ->size('lg')
                            ->weight('bold')
                            ->fontFamily(FontFamily::Mono)
                            ->copyable(),
                        TextEntry::make('paybill')
                            ->label('Paybill number')
                            ->state(fn (): string => config('services.mpesa.shortcode') ?: 'Not configured'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withBalance()->withCount('activeLeases'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('account_number')
                    ->label('Account')
                    ->searchable()
                    ->sortable()
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (Tenant $record): string => $record->type->getLabel()),
                TextColumn::make('phone')
                    ->searchable()
                    ->icon(Heroicon::OutlinedPhone),
                TextColumn::make('active_leases_count')
                    ->label('Leases')
                    ->alignCenter(),
                TextColumn::make('balance')
                    ->state(fn (Tenant $record): string => $record->balance())
                    ->formatStateUsing(fn (string $state): string => bccomp($state, '0', 2) < 0 ? Number::format(abs((float) $state), 2).' CR' : Number::format((float) $state, 2))
                    ->color(fn (string $state): ?string => match (bccomp($state, '0', 2)) {
                        1 => 'danger',
                        -1 => 'success',
                        default => null,
                    })
                    ->label('Balance (KES)')
                    ->alignEnd()
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw('(COALESCE(billed_total, 0) - COALESCE(paid_total, 0) - COALESCE(credited_total, 0)) '.($direction === 'desc' ? 'desc' : 'asc'))),
                TextColumn::make('kra_pin')
                    ->label('KRA PIN')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('email')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('owes')
                    ->label('Owes money')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereRaw(
                        '(select coalesce(sum(total), 0) from invoices where invoices.tenant_id = tenants.id and invoices.status != ?) > (select coalesce(sum(amount), 0) from payments where payments.tenant_id = tenants.id and payments.status = ?) + (select coalesce(sum(amount), 0) from credit_notes where credit_notes.tenant_id = tenants.id)',
                        [InvoiceStatus::Void->value, PaymentStatus::Posted->value],
                    )),
                SelectFilter::make('type')->options(TenantType::class),
                TernaryFilter::make('is_active')->label('Active')->default(true),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading('No tenants yet');
    }

    public static function getRelations(): array
    {
        return [
            LeasesRelationManager::class,
            InvoicesRelationManager::class,
            PaymentsRelationManager::class,
            CreditNotesRelationManager::class,
            RepaymentPlansRelationManager::class,
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenants::route('/'),
            'create' => CreateTenant::route('/create'),
            'view' => ViewTenant::route('/{record}'),
            'edit' => EditTenant::route('/{record}/edit'),
        ];
    }

    /**
     * Store Kenyan mobile numbers in international format: +254712345678.
     */
    public static function normalisePhone(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        return '+254'.substr($digits, str_starts_with($digits, '254') ? 3 : 1);
    }

    /**
     * Only users allowed to see KYC data get the full ID number (Data Protection Act, 2019).
     */
    private static function maskedIdNumber(Tenant $tenant): ?string
    {
        if (blank($tenant->id_number)) {
            return null;
        }

        if (Auth::user()?->can('viewDocuments', $tenant)) {
            return $tenant->id_number;
        }

        return str_repeat('•', max(0, strlen($tenant->id_number) - 3)).substr($tenant->id_number, -3);
    }
}
