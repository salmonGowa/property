<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Leases\LeaseResource;
use App\Models\Lease;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Auth;

class ExpiringLeases extends TableWidget
{
    protected static ?int $sort = 3;

    protected static ?string $heading = 'Leases expiring in the next 60 days';

    public static function canView(): bool
    {
        return Auth::user()?->canViewProperties() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Lease::query()->expiringWithin(60)->with(['tenant', 'unit.property']))
            ->defaultSort('end_date')
            ->paginated([5])
            ->columns([
                TextColumn::make('tenant.name'),
                TextColumn::make('unit')->state(fn (Lease $record): string => $record->unit->fullCode()),
                TextColumn::make('end_date')
                    ->label('Ends')
                    ->date()
                    ->description(fn (Lease $record): string => today()->diffInDays($record->end_date).' days'),
            ])
            ->recordActions([
                Action::make('open')->url(fn (Lease $record): string => LeaseResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading('No leases expiring soon');
    }
}
