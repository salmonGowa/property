<?php

namespace App\Filament\Resources\PenaltyRules;

use App\Enums\ChargeType;
use App\Enums\PenaltyCapType;
use App\Enums\PenaltyMethod;
use App\Enums\PenaltyRecurrence;
use App\Filament\Resources\PenaltyRules\Pages\ManagePenaltyRules;
use App\Models\PenaltyRule;
use App\Services\Arrears\PenaltyAssessment;
use App\Services\Arrears\PenaltyService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use UnitEnum;

class PenaltyRuleResource extends Resource
{
    protected static ?string $model = PenaltyRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Billing & Collections';

    protected static ?string $navigationLabel = 'Penalty Rules';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        $is = fn (Get $get, string $field, BackedEnum $case): bool => in_array($get($field), [$case, $case->value], true);

        return $schema
            ->columns(2)
            ->components([
                Callout::make(fn (Get $get): string => self::draft($get)->summary())
                    ->description(fn (Get $get): string => self::example($get))
                    ->icon(Heroicon::OutlinedLightBulb)
                    ->info()
                    ->columnSpanFull(),
                Section::make('Rule')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. Residential - 5% after 5 days'),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->inline(false),
                        Textarea::make('description')
                            ->rows(2)
                            ->placeholder('Where this applies, e.g. the clause number in the tenancy agreement'),
                        Toggle::make('is_default')
                            ->label('Company default')
                            ->helperText('Used for every lease whose property and lease have no rule of their own. Only one rule can be the default.')
                            ->inline(false),
                    ]),
                Section::make('How much')
                    ->schema([
                        ToggleButtons::make('method')
                            ->options(PenaltyMethod::class)
                            ->default(PenaltyMethod::Percentage->value)
                            ->required()
                            ->live(),
                        TextInput::make('rate')
                            ->label(fn (Get $get): string => $is($get, 'method', PenaltyMethod::Flat) ? 'Fee' : 'Rate')
                            ->prefix(fn (Get $get): ?string => $is($get, 'method', PenaltyMethod::Flat) ? 'KES' : null)
                            ->suffix(fn (Get $get): ?string => match (true) {
                                $is($get, 'method', PenaltyMethod::Flat) => null,
                                $is($get, 'method', PenaltyMethod::DailyPercentage) => '% per day',
                                default => '%',
                            })
                            ->numeric()
                            ->minValue(0.0001)
                            ->maxValue(fn (Get $get): ?int => $is($get, 'method', PenaltyMethod::Flat) ? null : 100)
                            ->required()
                            ->live(onBlur: true),
                        CheckboxList::make('charge_types')
                            ->label('Charged on overdue')
                            ->options([
                                ChargeType::Rent->value => ChargeType::Rent->getLabel(),
                                ChargeType::ServiceCharge->value => ChargeType::ServiceCharge->getLabel(),
                                ChargeType::Utility->value => ChargeType::Utility->getLabel(),
                                ChargeType::Other->value => ChargeType::Other->getLabel(),
                            ])
                            ->default([ChargeType::Rent->value, ChargeType::ServiceCharge->value])
                            ->required()
                            ->columns(2)
                            ->helperText('Penalties are never charged on deposits or on other penalties.')
                            ->live(),
                    ]),
                Section::make('When')
                    ->schema([
                        TextInput::make('grace_days')
                            ->label('Grace period')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(90)
                            ->default(5)
                            ->suffix('days after due date')
                            ->required()
                            ->live(onBlur: true),
                        ToggleButtons::make('recurrence')
                            ->label('Repeat')
                            ->options(PenaltyRecurrence::class)
                            ->default(PenaltyRecurrence::Once->value)
                            ->required()
                            ->live(),
                        TextInput::make('recurrence_days')
                            ->label('Every')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(365)
                            ->suffix('days')
                            ->visible(fn (Get $get): bool => $is($get, 'recurrence', PenaltyRecurrence::EveryNDays))
                            ->required(fn (Get $get): bool => $is($get, 'recurrence', PenaltyRecurrence::EveryNDays))
                            ->live(onBlur: true),
                        DatePicker::make('effective_from')
                            ->default(today())
                            ->required()
                            ->helperText('Assessments dated before this are never charged, so changing a rule never back-charges tenants.'),
                    ]),
                Section::make('Limits')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextInput::make('minimum_overdue')
                            ->label('Ignore balances under')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->prefix('KES'),
                        ToggleButtons::make('cap_type')
                            ->label('Cap per invoice')
                            ->options(PenaltyCapType::class)
                            ->default(PenaltyCapType::None->value)
                            ->required()
                            ->live(),
                        TextInput::make('cap_value')
                            ->label('Cap')
                            ->numeric()
                            ->minValue(0.01)
                            ->prefix(fn (Get $get): ?string => $is($get, 'cap_type', PenaltyCapType::Amount) ? 'KES' : null)
                            ->suffix(fn (Get $get): ?string => $is($get, 'cap_type', PenaltyCapType::PercentOfInvoice) ? '% of invoice' : null)
                            ->visible(fn (Get $get): bool => ! $is($get, 'cap_type', PenaltyCapType::None))
                            ->required(fn (Get $get): bool => ! $is($get, 'cap_type', PenaltyCapType::None))
                            ->live(onBlur: true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['properties', 'leases']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->weight('semibold')
                    ->searchable()
                    ->description(fn (PenaltyRule $record): string => ucfirst($record->summary())),
                TextColumn::make('is_default')
                    ->label('')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Company default' : '')
                    ->badge()
                    ->color('primary'),
                TextColumn::make('properties_count')->label('Properties')->alignCenter(),
                TextColumn::make('leases_count')->label('Leases')->alignCenter(),
                TextColumn::make('effective_from')->date(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                Action::make('preview')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->modalHeading(fn (PenaltyRule $record): string => "If penalties ran today: {$record->name}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema(fn (PenaltyRule $record): array => [Text::make(self::previewHtml($record))]),
                EditAction::make()->slideOver(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No penalty rules yet')
            ->emptyStateDescription('Create a rule and mark it as the company default, then override it per property or lease where terms differ.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePenaltyRules::route('/'),
        ];
    }

    /**
     * An unsaved rule built from the form, used for the live summary.
     */
    private static function draft(Get $get): PenaltyRule
    {
        return new PenaltyRule([
            'method' => $get('method') ?? PenaltyMethod::Percentage,
            'rate' => (string) ($get('rate') ?: 0),
            'grace_days' => (int) $get('grace_days'),
            'recurrence' => $get('recurrence') ?? PenaltyRecurrence::Once,
            'recurrence_days' => (int) $get('recurrence_days') ?: 1,
            'cap_type' => $get('cap_type') ?? PenaltyCapType::None,
            'cap_value' => (string) ($get('cap_value') ?: 0),
            'charge_types' => $get('charge_types') ?? [],
        ]);
    }

    /**
     * What the first assessment would be on a KES 30,000 rent invoice.
     */
    private static function example(Get $get): string
    {
        $rule = self::draft($get);
        $days = $rule->grace_days + 1;

        $amount = match ($rule->method) {
            PenaltyMethod::Percentage => 30000 * (float) $rule->rate / 100,
            PenaltyMethod::Flat => (float) $rule->rate,
            PenaltyMethod::DailyPercentage => 30000 * (float) $rule->rate / 100 * $days,
        };

        return 'Example: KES 30,000 rent due on the 5th and still unpaid is charged KES '.Number::format($amount, 2)
            .' on the '.Number::ordinal(5 + $days).'.';
    }

    private static function previewHtml(PenaltyRule $rule): HtmlString
    {
        $assessments = app(PenaltyService::class)->preview(today(), $rule);

        if ($assessments->isEmpty()) {
            return new HtmlString('<p>No penalties would be raised today under this rule.</p>');
        }

        $rows = $assessments->map(fn (PenaltyAssessment $assessment): string => sprintf(
            '<tr><td style="padding:4px 8px">%s</td><td style="padding:4px 8px">%s</td><td style="padding:4px 8px;text-align:right">%s</td><td style="padding:4px 8px;text-align:right"><strong>%s</strong></td></tr>',
            e($assessment->invoice->number),
            e($assessment->invoice->tenant->name),
            Number::format((float) $assessment->basis, 2),
            Number::format((float) $assessment->total(), 2),
        ))->implode('');

        return new HtmlString('<table style="width:100%;font-size:14px"><thead><tr><th style="text-align:left;padding:4px 8px">Invoice</th><th style="text-align:left;padding:4px 8px">Tenant</th><th style="text-align:right;padding:4px 8px">Overdue (KES)</th><th style="text-align:right;padding:4px 8px">Penalty (KES)</th></tr></thead><tbody>'
            .$rows.'</tbody></table><p style="margin-top:8px">Total: KES '.Number::format($assessments->sum(fn (PenaltyAssessment $assessment): float => (float) $assessment->total()), 2).'</p>');
    }
}
