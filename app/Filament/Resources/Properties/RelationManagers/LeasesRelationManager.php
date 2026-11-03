<?php

namespace App\Filament\Resources\Properties\RelationManagers;

use App\Filament\Resources\Leases\LeaseResource;
use Filament\Resources\RelationManagers\RelationManager;

class LeasesRelationManager extends RelationManager
{
    protected static string $relationship = 'leases';

    protected static ?string $relatedResource = LeaseResource::class;

    public function isReadOnly(): bool
    {
        return true;
    }
}
