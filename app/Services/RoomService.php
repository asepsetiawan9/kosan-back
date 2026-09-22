<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Room;
use App\Repositories\Contracts\RoomRepositoryInterface;
use App\Repositories\Contracts\TenancyRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RoomService
{
    public function __construct(
        private readonly RoomRepositoryInterface $roomRepository,
        private readonly TenancyRepositoryInterface $tenancyRepository
    ) {}

    public function getPaginatedRooms(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->roomRepository->getPaginated($filters, $perPage);
    }

    public function getAvailableRooms(): Collection
    {
        return $this->roomRepository->allAvailable();
    }

    public function getRoomById(string $id): Room
    {
        $room = $this->roomRepository->findById($id);
        if (!$room) {
            throw ValidationException::withMessages(['room' => ['Kamar tidak ditemukan.']]);
        }
        return $room;
    }

    public function createRoom(array $data, array $facilityIds = [], array $images = []): Room
    {
        return DB::transaction(function () use ($data, $facilityIds, $images) {
            $room = $this->roomRepository->create($data);

            if (!empty($facilityIds)) {
                $this->roomRepository->syncFacilities($room, $facilityIds);
            }

            foreach ($images as $index => $imageItem) {
                $imagePath = $this->processImage($imageItem);
                $isPrimary = $index === 0 || !empty($imageItem['is_primary']);
                $order = $imageItem['order'] ?? $index;
                $this->roomRepository->addImage($room, $imagePath, $isPrimary, $order);
            }

            return $room->fresh(['images', 'facilities']);
        });
    }

    public function updateRoom(string $id, array $data, ?array $facilityIds = null): Room
    {
        $room = $this->getRoomById($id);

        return DB::transaction(function () use ($room, $data, $facilityIds) {
            $updatedRoom = $this->roomRepository->update($room, $data);

            if ($facilityIds !== null) {
                $this->roomRepository->syncFacilities($updatedRoom, $facilityIds);
            }

            return $updatedRoom;
        });
    }

    public function deleteRoom(string $id): bool
    {
        $room = $this->getRoomById($id);

        // Integritas penghapusan: Kamar tidak dapat dihapus jika memiliki sewa aktif
        if ($this->tenancyRepository->hasActiveTenancy($room->id)) {
            throw ValidationException::withMessages([
                'room' => ['Kamar tidak dapat dihapus karena sedang dalam masa sewa aktif. Lakukan checkout terlebih dahulu.'],
            ]);
        }

        return $this->roomRepository->delete($room);
    }

    public function addRoomImage(string $roomId, mixed $image, bool $isPrimary = false, int $order = 0): void
    {
        $room = $this->getRoomById($roomId);
        $imagePath = $this->processImage($image);
        $this->roomRepository->addImage($room, $imagePath, $isPrimary, $order);
    }

    public function removeRoomImage(string $imageId): bool
    {
        return $this->roomRepository->removeImage($imageId);
    }

    private function processImage(mixed $imageItem): string
    {
        if ($imageItem instanceof UploadedFile) {
            return $imageItem->store('rooms', 'public');
        }

        if (is_array($imageItem) && isset($imageItem['file']) && $imageItem['file'] instanceof UploadedFile) {
            return $imageItem['file']->store('rooms', 'public');
        }

        if (is_array($imageItem) && isset($imageItem['url'])) {
            return (string) $imageItem['url'];
        }

        if (is_string($imageItem)) {
            return $imageItem;
        }

        return 'rooms/placeholder.jpg';
    }
}
