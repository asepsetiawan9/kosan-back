<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdmin() || $request->routeIs('admin.*') || str_contains($request->path(), 'admin');

        $signedKtpUrl = null;
        if ($isAdmin) {
            try {
                $signedKtpUrl = app(BookingService::class)->generateSignedKtpUrl($this->resource);
            } catch (\Throwable) {
                $signedKtpUrl = null;
            }
        }

        return [
            'id' => $this->id,
            'room_id' => $this->room_id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'requested_move_in' => $this->requested_move_in?->format('Y-m-d'),
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'ktp_preview_url' => $signedKtpUrl,
            'room' => $this->whenLoaded('room', function () {
                return [
                    'id' => $this->room->id,
                    'room_number' => $this->room->room_number,
                    'name' => $this->room->name,
                    'type' => $this->room->type,
                    'base_price' => (float) $this->room->base_price,
                    'status' => $this->room->status,
                    'primary_image' => $this->room->primaryImage?->image_path,
                ];
            }),
        ];
    }
}
