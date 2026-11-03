<?php

namespace App\Filament\Resources\Tenants\RelationManagers;

use App\Filament\Resources\Leases\LeaseResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LeasesRelationManager extends RelationManager
{
    protected static string $relationship = 'leases';

    protected static ?string $relatedResource = LeaseResource::class;

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->leases()->count();
    }

    public function table(Table $table): Table
    {
        return $table->headerActions([
            Action::make('newLease')
                ->label('New lease')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => LeaseResource::canCreate())
                ->url(fn (): string => LeaseResource::getUrl('create', ['tenant' => $this->getOwnerRecord()->getKey()])),
        ]);
    }
}
