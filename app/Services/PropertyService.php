<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Property;
use App\Repositories\Contracts\PropertyRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class PropertyService
{
    public function __construct(
        private readonly PropertyRepositoryInterface $propertyRepository
    ) {}

    public function getPaginatedProperties(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->propertyRepository->getPaginated($filters, $perPage);
    }

    public function getAllProperties(array $filters = []): Collection
    {
        return $this->propertyRepository->getAll($filters);
    }

    public function getPropertyById(string $id): Property
    {
        $property = $this->propertyRepository->findById($id);

        if (!$property) {
            throw ValidationException::withMessages([
                'property' => 'Properti tidak ditemukan.',
            ]);
        }

        return $property;
    }

    public function createProperty(array $data): Property
    {
        return $this->propertyRepository->create($data);
    }

    public function updateProperty(string $id, array $data): Property
    {
        $property = $this->getPropertyById($id);
        return $this->propertyRepository->update($property, $data);
    }

    public function deleteProperty(string $id): bool
    {
        $property = $this->getPropertyById($id);

        // Safeguard: Jangan izinkan hapus jika masih memiliki kamar terdaftar
        if ($property->rooms()->count() > 0) {
            throw ValidationException::withMessages([
                'property' => 'Tidak dapat menghapus properti yang masih memiliki kamar terdaftar. Pindahkan atau hapus kamar terlebih dahulu.',
            ]);
        }

        return $this->propertyRepository->delete($property);
    }
}
