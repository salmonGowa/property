<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Filament\Resources\Loans\LoanResource;
use App\Models\Loan;
use App\Services\Debt\LoanActionRefused;
use App\Services\Debt\LoanService;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditLoan extends EditRecord
{
    protected static string $resource = LoanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Loan $loan */
        $loan = $this->getRecord();

        return [...$data, 'properties' => $loan->properties()->pluck('properties.id')->all()];
    }

    /**
     * @param  Loan  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(LoanService::class)->update($record, [...$record->only(['lender_id', 'type', 'facility_amount', 'start_date', 'first_payment_date', 'maturity_date', 'payment_frequency', 'repayment_type', 'interest_only_periods', 'balloon_amount', 'rate_type', 'base_rate_name', 'base_rate', 'margin', 'interest_rate', 'liability_account_id', 'bank_account_id']), ...$data], $data['properties'] ?? []);
        } catch (LoanActionRefused $exception) {
            Notification::make()->title('Loan not saved')->body($exception->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
