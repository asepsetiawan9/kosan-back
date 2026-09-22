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
            'room_number' => $this->room_number,
            'name' => $this->name,
            'type' => $this->type,
            'base_price' => (float) $this->base_price,
            'description' => $this->description,
            'status' => $this->status,
            'is_available' => $this->status === 'kosong',
            'primary_image' => $this->primaryImage?->image_path ?: ($this->images->first()?->image_path),
            'images' => $this->images->map(fn($img) => [
                'id' => $img->id,
                'image_path' => $img->image_path,
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
