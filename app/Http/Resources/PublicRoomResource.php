<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicRoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'property' => $this->whenLoaded('property', function () {
                return $this->property ? [
                    'id' => $this->property->id,
                    'name' => $this->property->name,
                    'address' => $this->property->address,
                    'city' => $this->property->city,
                    'google_maps_url' => $this->property->google_maps_url,
                ] : null;
            }),
            'room_number' => $this->room_number,
            'name' => $this->name,
            'type' => $this->type,
            'base_price' => (float) $this->base_price,
            'description' => $this->description,
            'status' => $this->status,
            'is_available' => $this->status === 'kosong',
            'primary_image' => $this->primaryImage?->url ?: ($this->images->first()?->url ?? $this->primaryImage?->image_path),
            'images' => $this->images->map(fn($img) => [
                'id' => $img->id,
                'image_path' => $img->image_path,
                'url' => $img->url,
                'media_type' => $img->media_type ?? 'image',
                'video_thumbnail_path' => $img->video_thumbnail_path,
                'video_thumbnail_url' => $img->video_thumbnail_url,
                'video_duration' => $img->video_duration,
                'is_primary' => (bool) $img->is_primary,
                'order' => (int) $img->order,
            ]),
            'facilities' => $this->facilities->map(fn($fac) => [
                'id' => $fac->id,
                'name' => $fac->name,
                'icon_identifier' => $fac->icon_identifier,
                'category' => $fac->category,
            ]),
        ];
    }
}
