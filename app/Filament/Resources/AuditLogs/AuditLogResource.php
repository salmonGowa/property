<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Audit Trail';

    protected static ?string $recordTitleAttribute = 'event';

    public static function infolist(Schema $schema): Schema
    {
        $prettyJson = fn (?array $values): ?string => $values === null
            ? null
            : json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $schema
            ->columns(2)
            ->components([
                TextEntry::make('event'),
                TextEntry::make('created_at')
                    ->label('When')
                    ->dateTime(),
                TextEntry::make('user.name')
                    ->label('User')
                    ->placeholder('System'),
                TextEntry::make('ip_address')
                    ->label('IP address')
                    ->placeholder('-'),
                TextEntry::make('auditable_type')
                    ->label('Record')
                    ->formatStateUsing(fn (AuditLog $record): string => class_basename($record->auditable_type)." #{$record->auditable_id}")
                    ->placeholder('-'),
                TextEntry::make('hash')
                    ->fontFamily(FontFamily::Mono)
                    ->copyable(),
                TextEntry::make('old_values')
                    ->label('Before')
                    ->state(fn (AuditLog $record): ?string => $prettyJson($record->oldValues()))
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('new_values')
                    ->label('After')
                    ->state(fn (AuditLog $record): ?string => $prettyJson($record->newValues()))
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('-')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#'),
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('event')
                    ->badge()
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label('User')
                    ->placeholder('System'),
                TextColumn::make('auditable_id')
                    ->label('Record')
                    ->formatStateUsing(fn (AuditLog $record): string => class_basename($record->auditable_type)." #{$record->auditable_id}")
                    ->placeholder('-'),
                TextColumn::make('ip_address')
                    ->label('IP address')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('user')
                    ->relationship('user', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAuditLogs::route('/'),
        ];
    }
}
