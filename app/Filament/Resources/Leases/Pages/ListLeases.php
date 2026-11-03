<?php

namespace App\Filament\Resources\Leases\Pages;

use App\Enums\LeaseStatus;
use App\Filament\Resources\Leases\LeaseResource;
use App\Models\Lease;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListLeases extends ListRecords
{
    protected static string $resource = LeaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New lease'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Active')
                ->badge(Lease::query()->active()->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', LeaseStatus::Active)),
            'expiring' => Tab::make('Expiring in 60 days')
                ->badge(Lease::query()->expiringWithin(60)->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->expiringWithin(60)),
            'draft' => Tab::make('Drafts')
                ->badge(Lease::query()->where('status', LeaseStatus::Draft)->count())
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', LeaseStatus::Draft)),
            'terminated' => Tab::make('Terminated')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', LeaseStatus::Terminated)),
            'all' => Tab::make('All'),
        ];
    }
}
