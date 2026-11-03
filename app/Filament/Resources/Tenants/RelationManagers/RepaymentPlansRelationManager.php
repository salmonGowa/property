<?php

namespace App\Filament\Resources\Tenants\RelationManagers;

use App\Filament\Resources\RepaymentPlans\RepaymentPlanResource;
use Filament\Resources\RelationManagers\RelationManager;

class RepaymentPlansRelationManager extends RelationManager
{
    protected static string $relationship = 'repaymentPlans';

    protected static ?string $relatedResource = RepaymentPlanResource::class;

    public function isReadOnly(): bool
    {
        return true;
    }
}
