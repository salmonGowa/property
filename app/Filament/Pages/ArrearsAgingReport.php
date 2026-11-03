<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Tenants\TenantResource;
use App\Models\Property;
use App\Services\Arrears\ArrearsAging;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use UnitEnum;

class ArrearsAgingReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.arrears-aging-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Billing & Collections';

    protected static ?string $navigationLabel = 'Arrears Aging';

    protected static ?string $title = 'Arrears Aging';

    protected static ?int $navigationSort = 7;

    public static function canAccess(): bool
    {
        return Auth::user()?->canViewBilling() ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'What each tenant owes, by how long it has been overdue, as at '.today()->format('j M Y').'.';
    }

    public function table(Table $table): Table
    {
        $money = fn (string $state, array $record): string => bccomp($state, '0', 2) === 0 && ! $record['is_total'] ? '' : Number::format((float) $state, 2);
        $bold = fn (array $record): ?FontWeight => $record['is_total'] ? FontWeight::Bold : null;

        $bucketColumns = collect(ArrearsAging::BUCKETS)->map(fn (string $label, string $key): TextColumn => TextColumn::make($key)
            ->label($label)
            ->alignEnd()
            ->weight($bold)
            ->color(fn (string $state, array $record): ?string => in_array($key, ['days_61_90', 'days_over_90'], true) && bccomp($state, '0', 2) > 0 && ! $record['is_total'] ? 'danger' : null)
            ->formatStateUsing($money))->values()->all();

        return $table
            ->records(function (array $filters, ArrearsAging $aging): array {
                $rows = $aging->byTenant(filled($filters['property']['value'] ?? null) ? (int) $filters['property']['value'] : null);
                $totals = $aging->totals($rows);

                $records = $rows->mapWithKeys(fn (array $row): array => [$row['tenant']->id => [
                    'tenant_id' => $row['tenant']->id,
                    'name' => $row['tenant']->name,
                    'phone' => $row['tenant']->phone,
                    ...$row['buckets'],
                    'total' => $row['total'],
                    'is_total' => false,
                ]])->all();

                if ($records !== []) {
                    $records['total'] = ['tenant_id' => null, 'name' => 'Total', 'phone' => '', ...$totals['buckets'], 'total' => $totals['total'], 'is_total' => true];
                }

                return $records;
            })
            ->columns([
                TextColumn::make('name')
                    ->label('Tenant')
                    ->weight($bold)
                    ->description(fn (array $record): ?string => $record['phone'] ?: null),
                ...$bucketColumns,
                TextColumn::make('total')->label('Total (KES)')->alignEnd()->weight(FontWeight::Bold)->formatStateUsing($money),
            ])
            ->filters([
                SelectFilter::make('property')->options(fn (): array => Property::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                Action::make('open')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->visible(fn (array $record): bool => ! $record['is_total'])
                    ->url(fn (array $record): ?string => $record['tenant_id'] ? TenantResource::getUrl('view', ['record' => $record['tenant_id']]) : null),
            ])
            ->emptyStateHeading('Nobody owes anything')
            ->paginated(false);
    }
}
