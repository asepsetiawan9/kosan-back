<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicPropertyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $uniqueFacilities = collect();
        if ($this->relationLoaded('rooms')) {
            $uniqueFacilities = $this->rooms
                ->flatMap(fn($room) => $room->relationLoaded('facilities') ? $room->facilities : [])
                ->unique('id')
                ->values()
                ->map(fn($fac) => [
                    'id' => $fac->id,
                    'name' => $fac->name,
                    'icon_identifier' => $fac->icon_identifier,
                    'category' => $fac->category,
                ]);
        }

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
            'total_rooms' => $this->total_rooms,
            'available_rooms' => $this->available_rooms,
            'min_price' => $this->min_price,
            'max_price' => $this->max_price,
            'featured_media' => PropertyMediaResource::collection($this->whenLoaded('featuredMedia')),
            'media' => PropertyMediaResource::collection($this->whenLoaded('media')),
            'rooms' => PublicRoomResource::collection($this->whenLoaded('rooms')),
            'facilities' => $uniqueFacilities,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
