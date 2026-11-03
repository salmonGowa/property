<?php

namespace App\Filament\Resources\Vendors;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\WithholdingTaxType;
use App\Enums\WorkOrderCategory;
use App\Filament\Resources\Vendors\Pages\CreateVendor;
use App\Filament\Resources\Vendors\Pages\EditVendor;
use App\Filament\Resources\Vendors\Pages\ListVendors;
use App\Filament\Resources\Vendors\Pages\ViewVendor;
use App\Filament\Resources\Vendors\RelationManagers\BillsRelationManager;
use App\Filament\Resources\Vendors\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Vendors\RelationManagers\PurchaseOrdersRelationManager;
use App\Filament\Resources\Vendors\RelationManagers\WorkOrdersRelationManager;
use App\Models\Vendor;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class VendorResource extends Resource
{
    protected static ?string $model = Vendor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Procurement & Payables';

    protected static ?string $navigationLabel = 'Suppliers';

    protected static ?string $modelLabel = 'supplier';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'number', 'kra_pin', 'phone'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Number' => $record->number, 'Phone' => $record->phone];
    }

    /**
     * Supplier options for selects: active suppliers, optionally those covering a trade listed first.
     *
     * @return array<string, array<int, string>>|array<int, string>
     */
    public static function options(?string $category = null): array
    {
        $vendors = Vendor::query()->where('is_active', true)->orderBy('name')->get();

        if (blank($category)) {
            return $vendors->mapWithKeys(fn (Vendor $vendor): array => [$vendor->id => $vendor->name])->all();
        }

        [$covering, $others] = $vendors->partition(fn (Vendor $vendor): bool => in_array($category, $vendor->categories ?? [], true));
        $label = WorkOrderCategory::from($category)->getLabel();

        return array_filter([
            "Covers {$label}" => $covering->mapWithKeys(fn (Vendor $vendor): array => [$vendor->id => $vendor->name])->all(),
            'Other suppliers' => $others->mapWithKeys(fn (Vendor $vendor): array => [$vendor->id => $vendor->name])->all(),
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        $paysBy = fn (Get $get, PaymentMethod ...$methods): bool => in_array($get('payment_method') instanceof PaymentMethod ? $get('payment_method') : PaymentMethod::tryFrom((string) $get('payment_method')), $methods, true);

        return $schema
            ->columns(3)
            ->components([
                Section::make('Supplier')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255)->columnSpanFull(),
                        TextInput::make('kra_pin')
                            ->label('KRA PIN')
                            ->maxLength(11)
                            ->regex('/^[AP]\d{9}[A-Z]$/i')
                            ->validationMessages(['regex' => 'A KRA PIN is a letter, nine digits and a letter, e.g. P051234567X.'])
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtoupper($state) : null)
                            ->unique(ignoreRecord: true)
                            ->placeholder('P051234567X'),
                        TextInput::make('contact_person')->maxLength(255),
                        TextInput::make('phone')->tel()->maxLength(30),
                        TextInput::make('email')->email()->maxLength(255),
                        TextInput::make('address')->maxLength(255)->columnSpanFull(),
                        Select::make('categories')
                            ->label('Trades covered')
                            ->options(WorkOrderCategory::class)
                            ->multiple()
                            ->helperText('Suppliers covering a job\'s trade are listed first when assigning work.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Tax')
                    ->columnSpan(1)
                    ->schema([
                        Toggle::make('is_vat_registered')
                            ->label('VAT registered')
                            ->helperText('Only VAT-registered suppliers can charge VAT that you claim back as input VAT.'),
                        Select::make('withholding_tax_type')
                            ->label('Withholding tax')
                            ->options(WithholdingTaxType::class)
                            ->default(WithholdingTaxType::None->value)
                            ->required()
                            ->helperText('Deducted from every bill and paid to KRA by the 20th of the following month.'),
                        Toggle::make('is_active')->label('Active')->default(true),
                    ]),
                Section::make('How we pay them')
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        ToggleButtons::make('payment_method')
                            ->options(collect(PaymentMethod::disbursable())->mapWithKeys(fn (PaymentMethod $method): array => [$method->value => $method->getLabel()])->all())
                            ->default(PaymentMethod::BankTransfer->value)
                            ->inline()
                            ->live()
                            ->columnSpanFull(),
                        TextInput::make('bank_name')->visible(fn (Get $get): bool => $paysBy($get, PaymentMethod::BankTransfer))->required(fn (Get $get): bool => $paysBy($get, PaymentMethod::BankTransfer)),
                        TextInput::make('bank_branch')->visible(fn (Get $get): bool => $paysBy($get, PaymentMethod::BankTransfer)),
                        TextInput::make('bank_account_name')->label('Account name')->visible(fn (Get $get): bool => $paysBy($get, PaymentMethod::BankTransfer)),
                        TextInput::make('bank_account_number')->label('Account number')->visible(fn (Get $get): bool => $paysBy($get, PaymentMethod::BankTransfer))->required(fn (Get $get): bool => $paysBy($get, PaymentMethod::BankTransfer))->maxLength(40),
                        TextInput::make('mpesa_number')
                            ->label('M-Pesa paybill, till or phone')
                            ->visible(fn (Get $get): bool => $paysBy($get, PaymentMethod::Mpesa))
                            ->required(fn (Get $get): bool => $paysBy($get, PaymentMethod::Mpesa))
                            ->maxLength(30),
                        TextInput::make('payment_terms_days')
                            ->label('Payment terms')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(180)
                            ->default(30)
                            ->suffix('days')
                            ->required()
                            ->helperText('Bills fall due this many days after the bill date.'),
                    ]),
                Textarea::make('notes')->rows(3)->columnSpanFull(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make()
                    ->columnSpan(2)
                    ->columns(3)
                    ->schema([
                        TextEntry::make('number')->fontFamily(FontFamily::Mono),
                        TextEntry::make('kra_pin')->label('KRA PIN')->fontFamily(FontFamily::Mono)->copyable()->placeholder('Not given'),
                        IconEntry::make('is_active')->label('Active')->boolean(),
                        TextEntry::make('contact_person')->placeholder('-'),
                        TextEntry::make('phone')->url(fn (Vendor $record): ?string => $record->phone ? "tel:{$record->phone}" : null)->placeholder('-'),
                        TextEntry::make('email')->url(fn (Vendor $record): ?string => $record->email ? "mailto:{$record->email}" : null)->placeholder('-'),
                        TextEntry::make('categories')
                            ->label('Trades covered')
                            ->state(fn (Vendor $record): array => collect($record->categories ?? [])->map(fn (string $category): string => WorkOrderCategory::from($category)->getLabel())->all())
                            ->badge()
                            ->placeholder('-')
                            ->columnSpanFull(),
                        TextEntry::make('payment_details')
                            ->label('Pay to')
                            ->state(fn (Vendor $record): ?string => $record->paymentDetails())
                            ->placeholder('No payment details')
                            ->helperText(fn (Vendor $record): ?string => $record->payment_method?->getLabel()),
                        TextEntry::make('payment_terms_days')->label('Terms')->suffix(' days'),
                        TextEntry::make('tax')
                            ->state(fn (Vendor $record): string => ($record->is_vat_registered ? 'VAT registered' : 'Not VAT registered').' · WHT: '.$record->withholding_tax_type->getLabel()),
                        TextEntry::make('notes')->placeholder('-')->columnSpanFull(),
                    ]),
                Section::make('Account')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('balance')
                            ->label('We owe')
                            ->state(fn (Vendor $record): string => $record->balance())
                            ->money('KES')
                            ->inlineLabel()
                            ->size('lg')
                            ->weight('bold'),
                        TextEntry::make('overdue')
                            ->state(fn (Vendor $record): string => bcadd((string) $record->bills()->overdue()->selectRaw('COALESCE(SUM(payable_amount - amount_paid), 0) as owed')->value('owed'), '0', 2))
                            ->money('KES')
                            ->inlineLabel()
                            ->color(fn (string $state): ?string => bccomp($state, '0', 2) > 0 ? 'danger' : null),
                        TextEntry::make('billed_this_year')
                            ->state(fn (Vendor $record): string => bcadd((string) $record->bills()->where('status', '!=', InvoiceStatus::Void)->whereYear('bill_date', today()->year)->sum('total'), '0', 2))
                            ->money('KES')
                            ->inlineLabel(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(['name', 'number', 'kra_pin'])
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (Vendor $record): string => collect($record->categories ?? [])->map(fn (string $category): string => WorkOrderCategory::from($category)->getLabel())->implode(', ')),
                TextColumn::make('number')->fontFamily(FontFamily::Mono)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('contact_person')->description(fn (Vendor $record): ?string => $record->phone)->placeholder('-'),
                TextColumn::make('kra_pin')->label('KRA PIN')->fontFamily(FontFamily::Mono)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('balance')
                    ->label('We owe')
                    ->state(fn (Vendor $record): string => $record->balance())
                    ->money('KES')
                    ->alignEnd()
                    ->color(fn (string $state): ?string => bccomp($state, '0', 2) > 0 ? 'warning' : 'gray'),
                IconColumn::make('is_vat_registered')->label('VAT')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Trade')
                    ->options(WorkOrderCategory::class)
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, string $category) => $query->whereJsonContains('categories', $category))),
                TernaryFilter::make('is_active')->label('Active')->default(true),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading('No suppliers yet')
            ->emptyStateDescription('Add the contractors and suppliers you buy from, with their KRA PIN and payment details.');
    }

    public static function getRelations(): array
    {
        return [
            WorkOrdersRelationManager::class,
            PurchaseOrdersRelationManager::class,
            BillsRelationManager::class,
            PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendors::route('/'),
            'create' => CreateVendor::route('/create'),
            'view' => ViewVendor::route('/{record}'),
            'edit' => EditVendor::route('/{record}/edit'),
        ];
    }
}
