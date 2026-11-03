<?php

namespace App\Filament\Resources\RepaymentPlans\Pages;

use App\Filament\Resources\RepaymentPlans\RepaymentPlanResource;
use App\Models\RepaymentPlan;
use App\Services\Arrears\RepaymentPlanService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewRepaymentPlan extends ViewRecord
{
    protected static string $resource = RepaymentPlanResource::class;

    public function getTitle(): string
    {
        return "Repayment plan - {$this->getRecord()->tenant->name}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel')
                ->label('Cancel plan')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->authorize('cancel')
                ->modalDescription('Penalties resume on the arrears from the next daily run.')
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(function (array $data, RepaymentPlan $record, RepaymentPlanService $plans): void {
                    $plans->cancel($record, $data['reason']);
                    $this->refreshFormData(['status', 'close_reason']);
                }),
        ];
    }
}
