<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenancyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'room_id' => $this->room_id,
            'user_id' => $this->user_id,
            'tenant_name' => $this->tenant_name,
            'tenant_phone' => $this->tenant_phone,
            'tenant_email' => $this->tenant_email,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'billing_due_day' => $this->billing_due_day,
            'deposit_amount' => (float) $this->deposit_amount,
            'deposit_status' => $this->deposit_status,
            'status' => $this->status,
            'deposit_deduction' => $this->deposit_deduction !== null ? (float) $this->deposit_deduction : null,
            'deduction_reason' => $this->deduction_reason,
            'checkout_date' => $this->checkout_date?->format('Y-m-d'),
            'room' => new RoomResource($this->whenLoaded('room')),
            'invoices' => InvoiceResource::collection($this->whenLoaded('invoices')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
