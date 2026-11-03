<?php

namespace App\Filament\Resources\Vendors\RelationManagers;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use Filament\Resources\RelationManagers\RelationManager;

class PurchaseOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'purchaseOrders';

    protected static ?string $relatedResource = PurchaseOrderResource::class;

    public function isReadOnly(): bool
    {
        return true;
    }
}
