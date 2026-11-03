<?php

namespace App\Filament\Resources\AuditLogs\Pages;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Services\Audit\AuditLogger;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageAuditLogs extends ManageRecords
{
    protected static string $resource = AuditLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('verifyChain')
                ->label('Verify integrity')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->action(function (AuditLogger $auditLogger): void {
                    $brokenId = $auditLogger->findFirstBrokenLink();

                    if ($brokenId === null) {
                        Notification::make()->title('Audit trail intact')->body('No entry has been altered, removed or inserted.')->success()->send();

                        return;
                    }

                    Notification::make()->title('Audit trail broken')->body("Verification fails at entry #{$brokenId}.")->danger()->persistent()->send();
                }),
        ];
    }
}
