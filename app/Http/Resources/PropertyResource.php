<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PropertyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'city' => $this->city,
            'province' => $this->province,
            'postal_code' => $this->postal_code,
            'latitude' => $this->latitude ? (float) $this->latitude : null,
            'longitude' => $this->longitude ? (float) $this->longitude : null,
            'google_maps_url' => $this->google_maps_url,
            'owner_name' => $this->owner_name,
            'owner_phone' => $this->owner_phone,
            'owner_email' => $this->owner_email,
            'managed_by' => $this->managed_by,
            'manager' => $this->whenLoaded('manager', function () {
                return $this->manager ? [
                    'id' => $this->manager->id,
                    'name' => $this->manager->name,
                    'email' => $this->manager->email,
                ] : null;
            }),
            'total_rooms' => $this->total_rooms,
            'available_rooms' => $this->available_rooms,
            'occupied_rooms' => $this->occupied_rooms,
            'min_price' => $this->min_price,
            'max_price' => $this->max_price,
            'media' => PropertyMediaResource::collection($this->whenLoaded('media')),
            'featured_media' => PropertyMediaResource::collection($this->whenLoaded('featuredMedia')),
            'rooms' => RoomResource::collection($this->whenLoaded('rooms')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
