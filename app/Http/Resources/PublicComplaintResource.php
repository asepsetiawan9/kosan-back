<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PublicComplaint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PublicComplaint
 */
class PublicComplaintResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reporter_name' => $this->reporter_name,
            'reporter_phone' => $this->reporter_phone,
            'property_id' => $this->property_id,
            'property' => $this->whenLoaded('property', fn() => [
                'id' => $this->property->id,
                'name' => $this->property->name,
                'address' => $this->property->address,
            ]),
            'room_number' => $this->room_number,
            'category' => $this->category,
            'description' => $this->description,
            'photos' => $this->photo_urls,
            'status' => $this->status,
            'admin_response' => $this->admin_response,
            'resolved_at' => $this->resolved_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
