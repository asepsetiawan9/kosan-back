<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * @mixin Contract
 */
class ContractResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user && $user->isAdmin();

        $streamRoute = $isAdmin ? 'admin.contracts.stream' : 'tenant.contracts.stream';

        $streamUrl = URL::temporarySignedRoute(
            $streamRoute,
            now()->addMinutes(30),
            ['id' => $this->id]
        );

        return [
            'id' => $this->id,
            'tenancy_id' => $this->tenancy_id,
            'contract_number' => $this->contract_number,
            'status' => $this->status,
            'signed_at' => $this->signed_at?->toIso8601String(),
            'is_signed' => $this->isSigned(),
            'stream_url' => $streamUrl,
            'created_at' => $this->created_at?->toIso8601String(),
            'tenancy' => $this->whenLoaded('tenancy', function () {
                return [
                    'id' => $this->tenancy->id,
                    'tenant_name' => $this->tenancy->tenant_name,
                    'tenant_phone' => $this->tenancy->tenant_phone,
                    'tenant_email' => $this->tenancy->tenant_email,
                    'start_date' => $this->tenancy->start_date?->format('Y-m-d'),
                    'end_date' => $this->tenancy->end_date?->format('Y-m-d'),
                    'billing_due_day' => $this->tenancy->billing_due_day,
                    'deposit_amount' => (float) $this->tenancy->deposit_amount,
                    'deposit_status' => $this->tenancy->deposit_status,
                    'room' => $this->tenancy->room ? [
                        'id' => $this->tenancy->room->id,
                        'room_number' => $this->tenancy->room->room_number,
                        'type' => $this->tenancy->room->type,
                        'price' => (float) $this->tenancy->room->price,
                    ] : null,
                ];
            }),
        ];
    }
}
