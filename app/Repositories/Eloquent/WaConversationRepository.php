<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\WaConversation;
use App\Repositories\Contracts\WaConversationRepositoryInterface;
use App\Support\PhoneNumber;
use Carbon\Carbon;

class WaConversationRepository implements WaConversationRepositoryInterface
{
    public function findByPhone(string $phone): ?WaConversation
    {
        $normalized = PhoneNumber::normalize($phone);
        return WaConversation::where('phone', $normalized)->first();
    }

    public function getOrCreate(string $phone, ?string $tenantId = null): WaConversation
    {
        $normalized = PhoneNumber::normalize($phone);

        return WaConversation::firstOrCreate(
            ['phone' => $normalized],
            [
                'tenant_id' => $tenantId,
                'state' => 'idle',
                'context' => null,
                'expires_at' => null,
            ]
        );
    }

    public function update(WaConversation $conversation, array $data): bool
    {
        return $conversation->update($data);
    }

    public function expireStale(?Carbon $threshold = null): int
    {
        $now = $threshold ?? Carbon::now();

        return WaConversation::where('state', '!=', 'idle')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->update([
                'state' => 'idle',
                'context' => null,
                'expires_at' => null,
            ]);
    }
}
