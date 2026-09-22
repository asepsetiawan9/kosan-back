<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Room;
use App\Models\RoomImage;
use App\Repositories\Contracts\RoomRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class RoomRepository implements RoomRepositoryInterface
{
    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Room::with(['images', 'facilities', 'activeTenancy']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('room_number', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('room_number', 'asc')->paginate($perPage);
    }

    public function allAvailable(): Collection
    {
        return Room::where('status', 'kosong')->orderBy('room_number')->get();
    }

    public function findById(string $id): ?Room
    {
        return Room::with(['images', 'facilities', 'activeTenancy.invoices'])->find($id);
    }

    public function create(array $data): Room
    {
        return Room::create($data);
    }

    public function update(Room $room, array $data): Room
    {
        $room->update($data);
        return $room->fresh(['images', 'facilities']);
    }

    public function delete(Room $room): bool
    {
        return (bool) $room->delete();
    }

    public function syncFacilities(Room $room, array $facilityIds): void
    {
        $room->facilities()->sync($facilityIds);
    }

    public function addImage(Room $room, string $imagePath, bool $isPrimary = false, int $order = 0): void
    {
        if ($isPrimary) {
            RoomImage::where('room_id', $room->id)->update(['is_primary' => false]);
        }

        RoomImage::create([
            'room_id' => $room->id,
            'image_path' => $imagePath,
            'is_primary' => $isPrimary,
            'order' => $order,
        ]);
    }

    public function removeImage(string $imageId): bool
    {
        $image = RoomImage::find($imageId);
        return $image ? (bool) $image->delete() : false;
    }
}
