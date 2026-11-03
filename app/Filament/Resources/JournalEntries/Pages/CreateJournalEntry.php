<?php

namespace App\Filament\Resources\JournalEntries\Pages;

use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Services\Ledger\InvalidJournalEntry;
use App\Services\Ledger\JournalLineData;
use App\Services\Ledger\PostingService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateJournalEntry extends CreateRecord
{
    protected static string $resource = JournalEntryResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array{entry_date: string, description: string, reference: ?string, lines: list<array{account_id: int|string, debit: ?string, credit: ?string, description: ?string}>}  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            $lines = array_map(fn (array $line): JournalLineData => new JournalLineData(
                (int) $line['account_id'],
                debit: (string) ($line['debit'] ?? ''),
                credit: (string) ($line['credit'] ?? ''),
                description: $line['description'] ?? null,
            ), array_values($data['lines']));

            return app(PostingService::class)->post(
                $data['entry_date'],
                $data['description'],
                $lines,
                $data['reference'] ?? null,
            );
        } catch (InvalidJournalEntry $exception) {
            Notification::make()
                ->title('Entry not posted')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return "Journal entry {$this->getRecord()->number} posted";
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
