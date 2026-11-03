<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Vendors\VendorResource;
use App\Services\Arrears\ArrearsAging;
use App\Services\Procurement\PayablesAging;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use UnitEnum;

class PayablesAgingReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.arrears-aging-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Procurement & Payables';

    protected static ?string $navigationLabel = 'Payables Aging';

    protected static ?string $title = 'Payables Aging';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        return Auth::user()?->canViewProcurement() ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'What the company owes each supplier (after withholding tax), by how long it has been overdue, as at '.today()->format('j M Y').'.';
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
            ->records(function (PayablesAging $aging): array {
                $rows = $aging->byVendor();

                $records = $rows->mapWithKeys(fn (array $row): array => [$row['vendor']->id => [
                    'vendor_id' => $row['vendor']->id,
                    'name' => $row['vendor']->name,
                    'details' => $row['vendor']->paymentDetails() ?? '',
                    ...$row['buckets'],
                    'total' => $row['total'],
                    'is_total' => false,
                ]])->all();

                if ($records !== []) {
                    $totals = $aging->totals($rows);
                    $records['total'] = ['vendor_id' => null, 'name' => 'Total', 'details' => '', ...$totals['buckets'], 'total' => $totals['total'], 'is_total' => true];
                }

                return $records;
            })
            ->columns([
                TextColumn::make('name')
                    ->label('Supplier')
                    ->weight($bold)
                    ->description(fn (array $record): ?string => $record['details'] ?: null),
                ...$bucketColumns,
                TextColumn::make('total')->label('Total (KES)')->alignEnd()->weight(FontWeight::Bold)->formatStateUsing($money),
            ])
            ->recordActions([
                Action::make('open')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->visible(fn (array $record): bool => ! $record['is_total'])
                    ->url(fn (array $record): ?string => $record['vendor_id'] ? VendorResource::getUrl('view', ['record' => $record['vendor_id']]) : null),
            ])
            ->emptyStateHeading('Nothing owed to suppliers')
            ->paginated(false);
    }
}
