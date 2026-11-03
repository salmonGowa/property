<?php

namespace App\Filament\Resources\RepaymentPlans;

use App\Enums\RepaymentPlanStatus;
use App\Filament\Resources\RepaymentPlans\Pages\ListRepaymentPlans;
use App\Filament\Resources\RepaymentPlans\Pages\ViewRepaymentPlan;
use App\Filament\Resources\Tenants\TenantResource;
use App\Models\RepaymentPlan;
use App\Models\Tenant;
use App\Services\Arrears\RepaymentPlanService;
use App\Services\Leasing\LeaseActionRefused;
use App\Services\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as EntryColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use UnitEnum;

class RepaymentPlanResource extends Resource
{
    protected static ?string $model = RepaymentPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDateRange;

    protected static string|UnitEnum|null $navigationGroup = 'Billing & Collections';

    protected static ?int $navigationSort = 5;

    public static function getNavigationBadge(): ?string
    {
        $active = RepaymentPlan::query()->where('status', RepaymentPlanStatus::Active)->count();

        return $active > 0 ? (string) $active : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Active repayment plans';
    }

    /**
     * "Agree repayment plan" for a tenant in arrears.
     */
    public static function agreeAction(Tenant $tenant): Action
    {
        return Action::make('agreeRepaymentPlan')
            ->label('Agree repayment plan')
            ->icon(Heroicon::OutlinedCalendarDateRange)
            ->color('info')
            ->authorize(fn (): bool => Gate::allows('create', RepaymentPlan::class))
            ->visible(fn (): bool => Money::isPositive($tenant->balance()) && ! $tenant->repaymentPlans()->where('status', RepaymentPlanStatus::Active)->exists())
            ->modalDescription(fn (): string => 'Arrears of KES '.Number::format((float) $tenant->balance(), 2).' will be split into instalments. While the plan is kept, these arrears are not penalised; a missed instalment ends the plan and penalties resume.')
            ->schema([
                TextInput::make('instalments')
                    ->label('Number of instalments')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(36)
                    ->default(3)
                    ->required()
                    ->live(onBlur: true),
                Select::make('interval_months')
                    ->label('Every')
                    ->options([1 => 'Month', 2 => '2 months', 3 => '3 months'])
                    ->default(1)
                    ->required()
                    ->live(),
                DatePicker::make('first_due_date')
                    ->label('First instalment due')
                    ->default(today()->addMonthNoOverflow()->startOfMonth()->addDays(4))
                    ->minDate(today())
                    ->required()
                    ->live(),
                TextInput::make('grace_days')
                    ->label('Grace before a missed instalment ends the plan')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(30)
                    ->default(3)
                    ->suffix('days')
                    ->required(),
                Text::make(function (Get $get) use ($tenant): string {
                    $count = max(1, (int) $get('instalments'));
                    $each = (float) $tenant->balance() / $count;
                    $last = $get('first_due_date') ? Carbon::parse($get('first_due_date'))->addMonthsNoOverflow(($count - 1) * (int) $get('interval_months'))->format('j M Y') : '-';

                    return "{$count} instalment(s) of about KES ".Number::format($each, 2).", the last due {$last}. The tenant must also keep paying new rent.";
                })->color('gray'),
                Textarea::make('terms')->label('Agreed terms / notes')->rows(3),
            ])
            ->action(function (array $data, RepaymentPlanService $plans) use ($tenant): void {
                try {
                    $plans->create($tenant, $data['first_due_date'], (int) $data['instalments'], (int) $data['interval_months'], (int) $data['grace_days'], $data['terms'] ?? null);
                } catch (LeaseActionRefused $exception) {
                    Notification::make()->title('Plan not created')->body($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Repayment plan agreed')->success()->send();
            });
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
                        TextEntry::make('tenant.name')->weight('bold')->url(fn (RepaymentPlan $record): string => TenantResource::getUrl('view', ['record' => $record->tenant_id])),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('agreed_on')->date(),
                        TextEntry::make('terms')->placeholder('-')->columnSpanFull(),
                        TextEntry::make('close_reason')->label('Closed because')->visible(fn (RepaymentPlan $record): bool => $record->closed_at !== null),
                        TextEntry::make('creator.name')->label('Agreed by')->placeholder('-'),
                    ]),
                Section::make('Progress')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('arrears_amount')->label('Agreed arrears')->money('KES')->inlineLabel(),
                        TextEntry::make('paid')->state(fn (RepaymentPlan $record): string => app(RepaymentPlanService::class)->paidSoFar($record))->money('KES')->inlineLabel()->color('success'),
                        TextEntry::make('remaining')->state(fn (RepaymentPlan $record): string => app(RepaymentPlanService::class)->remaining($record))->money('KES')->inlineLabel()->weight('bold'),
                        TextEntry::make('expected')
                            ->label('Expected by now')
                            ->state(fn (RepaymentPlan $record): string => app(RepaymentPlanService::class)->overdueSchedule($record, today()))
                            ->money('KES')
                            ->inlineLabel(),
                    ]),
                RepeatableEntry::make('instalments')
                    ->columnSpanFull()
                    ->table([EntryColumn::make('#'), EntryColumn::make('Due'), EntryColumn::make('Amount'), EntryColumn::make('Status')])
                    ->schema([
                        TextEntry::make('sequence'),
                        TextEntry::make('due_date')->date(),
                        TextEntry::make('amount')->money('KES'),
                        TextEntry::make('instalment_status')
                            ->state(function ($record): string {
                                $plan = $record->plan;
                                $coveredBefore = Money::sum($plan->instalments->where('sequence', '<=', $record->sequence)->map(fn ($instalment): string => (string) $instalment->amount));
                                $paid = app(RepaymentPlanService::class)->paidSoFar($plan);

                                return match (true) {
                                    bccomp($paid, $coveredBefore, 2) >= 0 => 'Paid',
                                    $record->due_date->copy()->addDays($plan->grace_days)->lt(today()) => 'Missed',
                                    $record->due_date->lt(today()) => 'In grace period',
                                    default => 'Upcoming',
                                };
                            })
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'Paid' => 'success',
                                'Missed' => 'danger',
                                'In grace period' => 'warning',
                                default => 'gray',
                            }),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['tenant', 'instalments']))
            ->defaultSort('agreed_on', 'desc')
            ->columns([
                TextColumn::make('tenant.name')->searchable()->weight('semibold')->hidden(fn ($livewire): bool => $livewire instanceof RelationManager),
                TextColumn::make('agreed_on')->date()->sortable(),
                TextColumn::make('arrears_amount')->label('Arrears')->money('KES')->alignEnd(),
                TextColumn::make('remaining')->state(fn (RepaymentPlan $record): string => app(RepaymentPlanService::class)->remaining($record))->money('KES')->alignEnd(),
                TextColumn::make('instalments_count')->label('Instalments')->counts('instalments')->alignCenter(),
                TextColumn::make('next_due')
                    ->label('Next due')
                    ->state(fn (RepaymentPlan $record): ?string => $record->instalments->first(fn ($instalment): bool => $instalment->due_date->gte(today()))?->due_date->format('j M Y'))
                    ->placeholder('-'),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No repayment plans')
            ->emptyStateDescription('Agree a plan from a tenant\'s page when they are in arrears.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRepaymentPlans::route('/'),
            'view' => ViewRepaymentPlan::route('/{record}'),
        ];
    }
}
