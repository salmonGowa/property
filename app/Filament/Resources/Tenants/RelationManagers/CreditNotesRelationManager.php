<?php

namespace App\Filament\Resources\Tenants\RelationManagers;

use App\Filament\Resources\CreditNotes\CreditNoteResource;
use Filament\Resources\RelationManagers\RelationManager;

class CreditNotesRelationManager extends RelationManager
{
    protected static string $relationship = 'creditNotes';

    protected static ?string $relatedResource = CreditNoteResource::class;

    public function isReadOnly(): bool
    {
        return true;
    }
}
