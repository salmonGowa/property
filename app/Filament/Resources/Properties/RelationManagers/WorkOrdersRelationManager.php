<?php

namespace App\Filament\Resources\Properties\RelationManagers;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use Filament\Resources\RelationManagers\RelationManager;

class WorkOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'workOrders';

    protected static ?string $relatedResource = WorkOrderResource::class;

    public function isReadOnly(): bool
    {
        return true;
    }
}
