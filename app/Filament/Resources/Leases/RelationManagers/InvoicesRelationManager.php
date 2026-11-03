<?php

namespace App\Filament\Resources\Leases\RelationManagers;

use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Resources\RelationManagers\RelationManager;

class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    protected static ?string $relatedResource = InvoiceResource::class;

    public function isReadOnly(): bool
    {
        return true;
    }
}
