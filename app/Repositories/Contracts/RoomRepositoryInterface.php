<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Room;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface RoomRepositoryInterface
{
    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator;
    public function allAvailable(): Collection;
    public function findById(string $id): ?Room;
    public function create(array $data): Room;
    public function update(Room $room, array $data): Room;
    public function delete(Room $room): bool;
    public function syncFacilities(Room $room, array $facilityIds): void;
    public function addImage(Room $room, string $imagePath, bool $isPrimary = false, int $order = 0): void;
    public function removeImage(string $imageId): bool;
}
