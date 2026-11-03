<?php

namespace App\Filament\Resources\Vendors\RelationManagers;

use App\Filament\Resources\SupplierPayments\SupplierPaymentResource;
use Filament\Resources\RelationManagers\RelationManager;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $relatedResource = SupplierPaymentResource::class;

    public function isReadOnly(): bool
    {
        return true;
    }
}
