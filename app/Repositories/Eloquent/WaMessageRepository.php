<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\WaMessage;
use App\Repositories\Contracts\WaMessageRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class WaMessageRepository implements WaMessageRepositoryInterface
{
    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = WaMessage::with('tenant')->latest();

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['direction'])) {
            $query->where('direction', $filters['direction']);
        }

        if (!empty($filters['phone'])) {
            $query->where('phone', 'like', "%{$filters['phone']}%");
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('phone', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%")
                    ->orWhereHas('tenant', function ($t) use ($search) {
                        $t->where('name', 'like', "%{$search}%");
                    });
            });
        }

        return $query->paginate($perPage);
    }

    public function findById(string $id): ?WaMessage
    {
        return WaMessage::with('tenant')->find($id);
    }

    public function create(array $data): WaMessage
    {
        return WaMessage::create($data);
    }

    public function update(WaMessage $message, array $data): WaMessage
    {
        $message->update($data);
        return $message->fresh();
    }

    public function delete(WaMessage $message): bool
    {
        return (bool) $message->delete();
    }

    public function findByProviderMessageId(string $provider, string $direction, string $messageId): ?WaMessage
    {
        return WaMessage::where('provider', $provider)
            ->where('direction', $direction)
            ->where('provider_message_id', $messageId)
            ->first();
    }

    public function getRecentFailedCount(int $hours = 24): int
    {
        return WaMessage::where('status', 'failed')
            ->where('updated_at', '>=', now()->subHours($hours))
            ->count();
    }

    public function getRecentSentCount(int $hours = 24): int
    {
        return WaMessage::whereIn('status', ['sent', 'delivered'])
            ->where('sent_at', '>=', now()->subHours($hours))
            ->count();
    }
}
