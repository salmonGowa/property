<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Enums\ChargeType;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Lease;
use App\Models\Tenant;
use App\Services\Billing\InvoiceService;
use App\Services\Ledger\InvalidJournalEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected static bool $canCreateAnother = false;

    protected static ?string $title = 'New invoice';

    protected function afterFill(): void
    {
        if ($tenant = Tenant::query()->find(request()->query('tenant'))) {
            $this->form->fill([...$this->form->getRawState(), 'tenant_id' => $tenant->id]);
        }
    }

    /**
     * @param  array{tenant_id: int|string, lease_id: int|string|null, description: string, issue_date: string, due_date: string, lines: array<array{charge_type: string, description: string, amount: string, vat: bool}>}  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(InvoiceService::class)->issue(
                Tenant::query()->findOrFail($data['tenant_id']),
                $data['issue_date'],
                $data['due_date'],
                $data['description'],
                array_map(fn (array $line): array => [
                    'charge_type' => ChargeType::from($line['charge_type']),
                    'description' => $line['description'],
                    'amount' => (string) $line['amount'],
                    'vat' => (bool) ($line['vat'] ?? false),
                ], array_values($data['lines'])),
                $data['lease_id'] ? Lease::query()->find($data['lease_id']) : null,
            );
        } catch (InvalidJournalEntry $exception) {
            Notification::make()->title('Invoice not issued')->body($exception->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return "Invoice {$this->getRecord()->number} issued";
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
