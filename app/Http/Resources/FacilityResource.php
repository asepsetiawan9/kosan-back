<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FacilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon_identifier' => $this->icon_identifier,
            'category' => $this->category,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
