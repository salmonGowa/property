<?php

namespace App\Filament\Resources\PenaltyRules\Pages;

use App\Filament\Resources\PenaltyRules\PenaltyRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePenaltyRules extends ManageRecords
{
    protected static string $resource = PenaltyRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New penalty rule')->slideOver(),
        ];
    }
}
