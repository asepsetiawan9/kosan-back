<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\WaReminderRule $resource
 */
class WaReminderRuleResource extends JsonResource
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
            'name' => $this->name,
            'trigger_type' => $this->trigger_type,
            'offset_days' => (int) $this->offset_days,
            'send_time' => substr((string) $this->send_time, 0, 5),
            'template_key' => $this->template_key,
            'template_title' => $this->template?->title,
            'is_active' => (bool) $this->is_active,
            'logs_count' => (int) ($this->logs_count ?? $this->logs()->count()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
