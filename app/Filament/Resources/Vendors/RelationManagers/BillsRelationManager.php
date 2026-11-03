<?php

namespace App\Filament\Resources\Vendors\RelationManagers;

use App\Filament\Resources\SupplierBills\SupplierBillResource;
use Filament\Resources\RelationManagers\RelationManager;

class BillsRelationManager extends RelationManager
{
    protected static string $relationship = 'bills';

    protected static ?string $relatedResource = SupplierBillResource::class;

    public function isReadOnly(): bool
    {
        return true;
    }
}
