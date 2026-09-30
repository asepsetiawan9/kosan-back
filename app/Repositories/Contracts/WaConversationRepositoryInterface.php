<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\WaConversation;
use Carbon\Carbon;

interface WaConversationRepositoryInterface
{
    public function findByPhone(string $phone): ?WaConversation;

    public function getOrCreate(string $phone, ?string $tenantId = null): WaConversation;

    public function update(WaConversation $conversation, array $data): bool;

    public function expireStale(?Carbon $threshold = null): int;
}
