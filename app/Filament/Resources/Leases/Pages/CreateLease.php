<?php

namespace App\Filament\Resources\Leases\Pages;

use App\Filament\Resources\Leases\LeaseResource;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\Leasing\LeaseService;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

class CreateLease extends CreateRecord
{
    use HasWizard;

    protected static string $resource = LeaseResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * Arriving from a unit's "Let unit" button or a tenant's "New lease" button pre-selects them.
     */
    protected function afterFill(): void
    {
        $state = $this->form->getRawState();

        if ($tenant = Tenant::query()->find(request()->query('tenant'))) {
            $state['tenant_id'] = $tenant->id;
        }

        if ($unit = Unit::query()->find(request()->query('unit'))) {
            $state = [
                ...$state,
                'unit_id' => $unit->id,
                'rent_amount' => $unit->market_rent,
                'service_charge' => $unit->service_charge,
                'charge_vat' => $unit->type->isCommercial(),
            ];
        }

        $this->form->fill($state);
    }

    public function getSteps(): array
    {
        return [
            Step::make('Tenant & unit')
                ->icon(Heroicon::OutlinedUser)
                ->description('Who is renting what')
                ->columns(2)
                ->schema(LeaseResource::partiesFields()),
            Step::make('Term')
                ->icon(Heroicon::OutlinedCalendar)
                ->description('Dates and billing cycle')
                ->columns(2)
                ->schema(LeaseResource::termFields()),
            Step::make('Charges')
                ->icon(Heroicon::OutlinedBanknotes)
                ->description('Rent, deposit and VAT')
                ->columns(2)
                ->schema(LeaseResource::chargeFields()),
            Step::make('Late payment')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->description('Penalty rule')
                ->columns(2)
                ->schema(LeaseResource::penaltyFields()),
            Step::make('Review')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->description('Confirm and save as draft')
                ->schema([
                    Text::make(fn (Get $get): string => $this->summary($get)),
                    Textarea::make('notes')
                        ->rows(3)
                        ->placeholder('Special terms, e.g. rent-free fit-out period, parking allocation'),
                    Text::make('The lease is saved as a draft. Activate it from the next screen to occupy the unit and start billing.')
                        ->color('gray'),
                ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(LeaseService::class)->createDraft($data);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return "Lease {$this->getRecord()->reference} saved as draft";
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    private function summary(Get $get): string
    {
        $unit = Unit::query()->with('property')->find($get('unit_id'));
        $rent = (float) $get('rent_amount');
        $serviceCharge = (float) $get('service_charge');
        $vat = $get('charge_vat') ? ($rent + $serviceCharge) * (float) config('ledger.vat_rate') / 100 : 0;
        $start = $get('start_date') ? Carbon::parse($get('start_date'))->format('j M Y') : '-';
        $end = $get('end_date') ? Carbon::parse($get('end_date'))->format('j M Y') : 'periodic';

        return sprintf(
            '%s from %s to %s. Monthly: rent KES %s + service charge KES %s%s = KES %s. Deposit KES %s.',
            $unit?->fullCode() ?? 'Unit',
            $start,
            $end,
            Number::format($rent, 2),
            Number::format($serviceCharge, 2),
            $vat > 0 ? ' + VAT KES '.Number::format($vat, 2) : '',
            Number::format($rent + $serviceCharge + $vat, 2),
            Number::format((float) $get('deposit_amount'), 2),
        );
    }
}
