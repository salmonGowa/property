<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Services\Arrears\PenaltyService;
use App\Services\Billing\BillingService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runBilling')
                ->label('Run billing')
                ->icon(Heroicon::OutlinedPlay)
                ->color('gray')
                ->authorize('create', Invoice::class)
                ->modalDescription('Invoices rent and service charge for every active lease period starting on or before the date. Periods already billed are skipped, so this is safe to run at any time. It also runs automatically every morning.')
                ->schema([
                    DatePicker::make('up_to')->label('Bill periods starting up to')->default(today())->required(),
                ])
                ->action(function (array $data, BillingService $billing): void {
                    $result = $billing->run(Carbon::parse($data['up_to']));

                    Notification::make()
                        ->title(count($result->invoices).' invoice(s) issued')
                        ->body('Total KES '.Number::format((float) $result->total(), 2).'.'
                            .($result->failures === [] ? '' : ' Could not bill: '.collect($result->failures)->map(fn (string $message, string $lease): string => "{$lease} ({$message})")->implode('; ')))
                        ->status($result->failures === [] ? 'success' : 'warning')
                        ->persistent($result->failures !== [])
                        ->send();
                }),
            Action::make('assessPenalties')
                ->label('Assess penalties')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color('gray')
                ->authorize('create', Invoice::class)
                ->modalDescription(function (PenaltyService $penalties): string {
                    $preview = $penalties->preview(today());

                    return $preview->isEmpty()
                        ? 'No penalties are due today under the current penalty rules.'
                        : $preview->count().' overdue invoice(s) would be penalised, totalling KES '.Number::format($preview->sum(fn ($assessment): float => (float) $assessment->total()), 2).'. This also runs automatically every morning.';
                })
                ->modalSubmitActionLabel('Raise penalties')
                ->action(function (PenaltyService $penalties): void {
                    $result = $penalties->assess(today());

                    Notification::make()
                        ->title(count($result->invoices).' penalty invoice(s) raised')
                        ->body('Total KES '.Number::format((float) $result->total(), 2).'.'.($result->failures === [] ? '' : ' Could not penalise: '.implode('; ', array_keys($result->failures))))
                        ->status($result->failures === [] ? 'success' : 'warning')
                        ->send();
                }),
            CreateAction::make()->label('New invoice'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'outstanding' => Tab::make('Outstanding')
                ->badge(Invoice::query()->outstanding()->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->outstanding()),
            'overdue' => Tab::make('Overdue')
                ->badge(Invoice::query()->overdue()->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->overdue()),
            'paid' => Tab::make('Paid')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', InvoiceStatus::Paid)),
            'void' => Tab::make('Void')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', InvoiceStatus::Void)),
            'all' => Tab::make('All'),
        ];
    }
}
