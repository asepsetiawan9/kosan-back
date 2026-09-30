<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WaMessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction,
            'phone' => $this->phone,
            'phone_display' => PhoneNumber::formatDisplay($this->phone),
            'provider' => $this->provider,
            'provider_message_id' => $this->provider_message_id,
            'type' => $this->type,
            'body' => $this->body,
            'template_key' => $this->template_key,
            'status' => $this->status,
            'error_message' => $this->error_message,
            'attempts' => $this->attempts,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'tenant' => $this->whenLoaded('tenant', fn() => [
                'id' => $this->tenant->id,
                'name' => $this->tenant->name,
                'email' => $this->tenant->email,
                'room_number' => $this->tenant->tenancies()->where('status', 'aktif')->latest()->first()?->room?->room_number,
            ]),
        ];
    }
}
