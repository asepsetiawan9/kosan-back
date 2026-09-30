<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\WaReminderLog $resource
 */
class WaReminderLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tenancy = $this->invoice?->tenancy;

        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'invoice_number' => $this->invoice?->invoice_number,
            'period' => $this->invoice?->period,
            'invoice_due_date' => $this->invoice?->due_date?->format('d/m/Y'),
            'total_amount' => (float) ($this->invoice?->total_amount ?? 0),
            'remaining_amount' => $this->invoice?->remaining_amount ?? 0.0,
            'tenant_name' => $tenancy?->tenant_name,
            'tenant_phone' => $tenancy?->tenant_phone,
            'room_number' => $tenancy?->room?->room_number,
            'rule_id' => $this->rule_id,
            'rule_name' => $this->rule?->name,
            'template_key' => $this->rule?->template_key,
            'sent_for_date' => $this->sent_for_date?->toDateString(),
            'wa_message_id' => $this->wa_message_id,
            'wa_message_status' => $this->waMessage?->status,
            'wa_message_body' => $this->waMessage?->body,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
