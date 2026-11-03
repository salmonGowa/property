<?php

namespace App\Filament\Resources\Units\RelationManagers;

use App\Filament\Resources\Leases\LeaseResource;
use Filament\Resources\RelationManagers\RelationManager;

class LeasesRelationManager extends RelationManager
{
    protected static string $relationship = 'leases';

    protected static ?string $relatedResource = LeaseResource::class;

    protected static ?string $title = 'Lease history';

    public function isReadOnly(): bool
    {
        return true;
    }
}
