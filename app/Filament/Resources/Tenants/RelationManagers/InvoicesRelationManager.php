<?php

namespace App\Filament\Resources\Tenants\RelationManagers;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    protected static ?string $relatedResource = InvoiceResource::class;

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $outstanding = $ownerRecord->invoices()->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::PartiallyPaid])->count();

        return $outstanding > 0 ? (string) $outstanding : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'danger';
    }

    public static function getBadgeTooltip(Model $ownerRecord, string $pageClass): string
    {
        return 'Outstanding invoices';
    }

    public function table(Table $table): Table
    {
        return $table->headerActions([
            Action::make('newInvoice')
                ->label('New invoice')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => InvoiceResource::canCreate())
                ->url(fn (): string => InvoiceResource::getUrl('create', ['tenant' => $this->getOwnerRecord()->getKey()])),
        ]);
    }
}
