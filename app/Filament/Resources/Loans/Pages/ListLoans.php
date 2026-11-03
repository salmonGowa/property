<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Enums\LoanStatus;
use App\Filament\Resources\Loans\LoanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListLoans extends ListRecords
{
    protected static string $resource = LoanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New loan facility'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Active')->modifyQueryUsing(fn (Builder $query) => $query->where('status', LoanStatus::Active)),
            'pending' => Tab::make('Not yet drawn')->modifyQueryUsing(fn (Builder $query) => $query->where('status', LoanStatus::Pending)),
            'closed' => Tab::make('Repaid')->modifyQueryUsing(fn (Builder $query) => $query->where('status', LoanStatus::Closed)),
            'all' => Tab::make('All'),
        ];
    }
}
