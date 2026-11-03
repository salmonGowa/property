<?php

namespace App\Filament\Resources\MpesaTransactions\Pages;

use App\Enums\MpesaTransactionStatus;
use App\Filament\Resources\MpesaTransactions\MpesaTransactionResource;
use App\Models\MpesaTransaction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageMpesaTransactions extends ManageRecords
{
    protected static string $resource = MpesaTransactionResource::class;

    public function getTabs(): array
    {
        return [
            'unmatched' => Tab::make('Needs matching')
                ->badge(MpesaTransaction::query()->where('status', MpesaTransactionStatus::Unmatched)->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', MpesaTransactionStatus::Unmatched)),
            'matched' => Tab::make('Matched')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', MpesaTransactionStatus::Matched)),
            'ignored' => Tab::make('Ignored')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', MpesaTransactionStatus::Ignored)),
            'all' => Tab::make('All'),
        ];
    }
}
