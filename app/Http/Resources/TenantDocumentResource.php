<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TenantDocument;
use App\Services\TenantDocumentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var TenantDocument $doc */
        $doc = $this->resource;
        $service = app(TenantDocumentService::class);
        $isAdmin = (bool) $request->user()?->isAdmin();

        return [
            'id' => $doc->id,
            'user_id' => $doc->user_id,
            'document_type' => $doc->document_type,
            'original_filename' => $doc->original_filename,
            'mime_type' => $doc->mime_type,
            'file_size' => $doc->file_size,
            'is_verified' => $doc->is_verified,
            'verified_at' => $doc->verified_at?->toIso8601String(),
            'verified_by' => $doc->verifier ? [
                'id' => $doc->verifier->id,
                'name' => $doc->verifier->name,
            ] : null,
            'notes' => $doc->notes,
            'stream_url' => $service->getSignedStreamUrl($doc, $isAdmin, 15),
            'created_at' => $doc->created_at?->toIso8601String(),
            'updated_at' => $doc->updated_at?->toIso8601String(),
        ];
    }
}
