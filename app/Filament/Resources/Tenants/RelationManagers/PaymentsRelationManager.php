<?php

namespace App\Filament\Resources\Tenants\RelationManagers;

use App\Filament\Resources\Payments\PaymentResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $relatedResource = PaymentResource::class;

    public function table(Table $table): Table
    {
        return $table->headerActions([
            PaymentResource::recordPaymentAction($this->getOwnerRecord()),
        ]);
    }
}
