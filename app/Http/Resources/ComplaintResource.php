<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ComplaintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $photoUrl = null;
        if ($this->photo) {
            $photoUrl = str_starts_with($this->photo, 'http')
                ? $this->photo
                : Storage::disk('public')->url($this->photo);
        }

        return [
            'id' => $this->id,
            'tenancy_id' => $this->tenancy_id,
            'category' => $this->category,
            'description' => $this->description,
            'photo' => $this->photo,
            'photo_url' => $photoUrl,
            'status' => $this->status,
            'admin_response' => $this->admin_response,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'tenancy' => $this->whenLoaded('tenancy', function () {
                return [
                    'id' => $this->tenancy->id,
                    'tenant_name' => $this->tenancy->tenant_name,
                    'tenant_phone' => $this->tenancy->tenant_phone,
                    'tenant_email' => $this->tenancy->tenant_email,
                    'room' => $this->tenancy->room ? [
                        'id' => $this->tenancy->room->id,
                        'room_number' => $this->tenancy->room->room_number,
                        'name' => $this->tenancy->room->name,
                        'type' => $this->tenancy->room->type,
                    ] : null,
                ];
            }),
        ];
    }
}
