<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\WaMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface WaMessageRepositoryInterface
{
    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator;
    public function findById(string $id): ?WaMessage;
    public function create(array $data): WaMessage;
    public function update(WaMessage $message, array $data): WaMessage;
    public function delete(WaMessage $message): bool;
    public function findByProviderMessageId(string $provider, string $direction, string $messageId): ?WaMessage;
    public function getRecentFailedCount(int $hours = 24): int;
    public function getRecentSentCount(int $hours = 24): int;
}
