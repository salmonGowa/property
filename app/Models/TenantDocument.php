<?php

namespace App\Models;

use App\Enums\KycDocumentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A KYC document stored on the private disk; it is only reachable through short-lived signed links.
 */
#[Fillable(['tenant_id', 'type', 'file_path', 'original_name', 'expires_on', 'uploaded_by'])]
class TenantDocument extends Model
{
    protected static function booted(): void
    {
        static::deleted(fn (TenantDocument $document) => Storage::disk('local')->delete($document->file_path));
    }

    protected function casts(): array
    {
        return [
            'type' => KycDocumentType::class,
            'expires_on' => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function temporaryUrl(): string
    {
        return Storage::disk('local')->temporaryUrl($this->file_path, now()->addMinutes(5));
    }
}
