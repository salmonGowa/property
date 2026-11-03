<?php

namespace App\Filament\Resources\Properties\RelationManagers;

use App\Filament\Resources\Units\UnitResource;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class UnitsRelationManager extends RelationManager
{
    protected static string $relationship = 'units';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->units()->count();
    }

    public function form(Schema $schema): Schema
    {
        return UnitResource::form($schema);
    }

    public function infolist(Schema $schema): Schema
    {
        return UnitResource::infolist($schema);
    }

    public function table(Table $table): Table
    {
        return UnitResource::table($table)
            ->headerActions([
                CreateAction::make()->label('Add unit'),
            ]);
    }
}
