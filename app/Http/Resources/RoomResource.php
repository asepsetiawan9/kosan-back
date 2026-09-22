<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'room_number' => $this->room_number,
            'name' => $this->name,
            'type' => $this->type,
            'base_price' => (float) $this->base_price,
            'description' => $this->description,
            'status' => $this->status,
            'primary_image' => $this->images?->firstWhere('is_primary', true)?->image_path 
                ?? $this->images?->first()?->image_path,
            'images' => $this->whenLoaded('images', function () {
                return $this->images->map(function ($img) {
                    return [
                        'id' => $img->id,
                        'image_path' => $img->image_path,
                        'is_primary' => (bool) $img->is_primary,
                        'order' => $img->order,
                    ];
                });
            }),
            'facilities' => FacilityResource::collection($this->whenLoaded('facilities')),
            'active_tenancy' => $this->whenLoaded('activeTenancy', function () {
                return $this->activeTenancy ? [
                    'id' => $this->activeTenancy->id,
                    'tenant_name' => $this->activeTenancy->tenant_name,
                    'tenant_phone' => $this->activeTenancy->tenant_phone,
                    'start_date' => $this->activeTenancy->start_date?->format('Y-m-d'),
                ] : null;
            }),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
