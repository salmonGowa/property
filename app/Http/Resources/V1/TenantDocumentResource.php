<?php

namespace App\Http\Resources\V1;

use App\Http\Resources\Concerns\FormatsEnums;
use App\Models\TenantDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TenantDocument
 */
class TenantDocumentResource extends JsonResource
{
    use FormatsEnums;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => self::enumData($this->type),
            'originalName' => $this->original_name,
            'expiresOn' => $this->expires_on?->toDateString(),
            'isExpired' => (bool) $this->expires_on?->isPast(),
            'uploadedBy' => $this->whenLoaded('uploader', fn (): ?string => $this->uploader?->name),
            'fileUrl' => route('api.v1.tenant-documents.file', $this->resource, absolute: false),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
