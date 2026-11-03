<?php

namespace App\Filament\Resources\Tenants\RelationManagers;

use App\Enums\KycDocumentType;
use App\Models\TenantDocument;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * KYC documents live on the private disk and are opened through 5-minute signed links.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'KYC documents';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('viewDocuments', $ownerRecord) ?? false;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->documents()->count();
    }

    public function isReadOnly(): bool
    {
        return ! (Auth::user()?->can('manageDocuments', $this->getOwnerRecord()) ?? false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('type')
                    ->options(KycDocumentType::class)
                    ->required(),
                DatePicker::make('expires_on')
                    ->helperText('For permits and passports; leave empty if it does not expire.'),
                FileUpload::make('file_path')
                    ->label('File')
                    ->disk('local')
                    ->directory(fn (): string => 'kyc/'.$this->getOwnerRecord()->getKey())
                    ->visibility('private')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                    ->maxSize(10240)
                    ->storeFileNamesIn('original_name')
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('original_name')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('type')->badge()->color('gray'),
                TextColumn::make('original_name')->label('File')->icon(Heroicon::OutlinedPaperClip),
                TextColumn::make('expires_on')
                    ->date()
                    ->placeholder('-')
                    ->color(fn (TenantDocument $record): ?string => $record->expires_on?->isPast() ? 'danger' : null),
                TextColumn::make('uploader.name')->label('Uploaded by'),
                TextColumn::make('created_at')->label('Uploaded')->since(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Upload document')
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'uploaded_by' => Auth::id()]),
            ])
            ->recordActions([
                Action::make('open')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (TenantDocument $record): string => $record->temporaryUrl())
                    ->openUrlInNewTab(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No documents')
            ->emptyStateDescription('Upload ID, KRA PIN certificate and, for companies, the CR12 and certificate of incorporation.');
    }
}
