<?php

namespace App\Filament\Resources\RepaymentPlans\Pages;

use App\Enums\RepaymentPlanStatus;
use App\Filament\Resources\RepaymentPlans\RepaymentPlanResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListRepaymentPlans extends ListRecords
{
    protected static string $resource = RepaymentPlanResource::class;

    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Active')->modifyQueryUsing(fn (Builder $query) => $query->where('status', RepaymentPlanStatus::Active)),
            'defaulted' => Tab::make('Defaulted')->badgeColor('danger')->modifyQueryUsing(fn (Builder $query) => $query->where('status', RepaymentPlanStatus::Defaulted)),
            'completed' => Tab::make('Completed')->modifyQueryUsing(fn (Builder $query) => $query->where('status', RepaymentPlanStatus::Completed)),
            'all' => Tab::make('All'),
        ];
    }
}
