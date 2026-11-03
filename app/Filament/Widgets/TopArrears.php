<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;

class TopArrears extends TableWidget
{
    protected static ?int $sort = 4;

    protected static ?string $heading = 'Largest overdue balances';

    public static function canView(): bool
    {
        return Auth::user()?->canViewBilling() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Tenant::query()
                ->whereHas('invoices', fn (Builder $invoices) => $invoices->overdue())
                ->withSum(['invoices as overdue_total' => fn (Builder $invoices) => $invoices->overdue()], 'total')
                ->withSum(['invoices as overdue_paid' => fn (Builder $invoices) => $invoices->overdue()], 'amount_paid')
                ->withMin(['invoices as oldest_due' => fn (Builder $invoices) => $invoices->overdue()], 'due_date')
                ->orderByRaw('(overdue_total - overdue_paid) desc'))
            ->paginated([5])
            ->columns([
                TextColumn::make('name')->description(fn (Tenant $record): string => $record->phone),
                TextColumn::make('overdue')
                    ->label('Overdue (KES)')
                    ->state(fn (Tenant $record): string => Number::format((float) $record->overdue_total - (float) $record->overdue_paid, 2))
                    ->alignEnd(),
                TextColumn::make('oldest_due')
                    ->label('Oldest due')
                    ->date()
                    ->description(fn (Tenant $record): string => today()->diffInDays($record->oldest_due).' days late'),
            ])
            ->recordActions([
                Action::make('open')->url(fn (Tenant $record): string => TenantResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading('No overdue balances');
    }
}
