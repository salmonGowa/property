<?php

namespace App\Filament\Resources\JournalEntries\Pages;

use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Models\JournalEntry;
use App\Services\Ledger\InvalidJournalEntry;
use App\Services\Ledger\PostingService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewJournalEntry extends ViewRecord
{
    protected static string $resource = JournalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewOriginal')
                ->label(fn (JournalEntry $record): string => "Reverses {$record->reverses?->number}")
                ->color('gray')
                ->visible(fn (JournalEntry $record): bool => $record->reverses_entry_id !== null)
                ->url(fn (JournalEntry $record): string => JournalEntryResource::getUrl('view', ['record' => $record->reverses_entry_id])),
            Action::make('viewReversal')
                ->label(fn (JournalEntry $record): string => "Reversed by {$record->reversal?->number}")
                ->color('gray')
                ->visible(fn (JournalEntry $record): bool => $record->reversal()->exists())
                ->url(fn (JournalEntry $record): string => JournalEntryResource::getUrl('view', ['record' => $record->reversal])),
            Action::make('reverse')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('danger')
                ->authorize('reverse')
                ->modalDescription('Posts a new entry with debits and credits swapped. The original entry stays on record.')
                ->schema([
                    DatePicker::make('date')
                        ->label('Reversal date')
                        ->required()
                        ->default(now()),
                    TextInput::make('reason')
                        ->required()
                        ->maxLength(200),
                ])
                ->action(function (array $data, JournalEntry $record, PostingService $posting): void {
                    try {
                        $reversal = $posting->reverse($record, $data['date'], $data['reason']);
                    } catch (InvalidJournalEntry $exception) {
                        Notification::make()->title('Not reversed')->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title("Reversed by {$reversal->number}")->success()->send();
                    $this->redirect(JournalEntryResource::getUrl('view', ['record' => $reversal]));
                }),
        ];
    }
}
