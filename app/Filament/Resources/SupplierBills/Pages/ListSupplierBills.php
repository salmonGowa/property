<?php

namespace App\Filament\Resources\SupplierBills\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Models\SupplierBill;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSupplierBills extends ListRecords
{
    protected static string $resource = SupplierBillResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Record bill'),
        ];
    }

    public function getTabs(): array
    {
        $dueThisWeek = fn (Builder $query) => $query->outstanding()->whereBetween('due_date', [today(), today()->addDays(7)]);

        return [
            'unpaid' => Tab::make('Unpaid')
                ->badge(SupplierBill::query()->outstanding()->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->outstanding()),
            'overdue' => Tab::make('Overdue')
                ->badge(SupplierBill::query()->overdue()->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->overdue()),
            'due_soon' => Tab::make('Due in 7 days')
                ->badge($dueThisWeek(SupplierBill::query())->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing($dueThisWeek),
            'paid' => Tab::make('Paid')->modifyQueryUsing(fn (Builder $query) => $query->where('status', InvoiceStatus::Paid)),
            'void' => Tab::make('Void')->modifyQueryUsing(fn (Builder $query) => $query->where('status', InvoiceStatus::Void)),
            'all' => Tab::make('All'),
        ];
    }
}
