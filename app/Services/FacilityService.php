<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Facility;
use App\Repositories\Contracts\FacilityRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class FacilityService
{
    public function __construct(
        private readonly FacilityRepositoryInterface $facilityRepository
    ) {}

    public function getAllFacilities(): Collection
    {
        return $this->facilityRepository->all();
    }

    public function createFacility(array $data): Facility
    {
        return $this->facilityRepository->create($data);
    }

    public function updateFacility(string $id, array $data): Facility
    {
        $facility = $this->facilityRepository->findById($id);
        if (!$facility) {
            throw new \InvalidArgumentException("Fasilitas tidak ditemukan.");
        }

        return $this->facilityRepository->update($facility, $data);
    }

    public function deleteFacility(string $id): bool
    {
        $facility = $this->facilityRepository->findById($id);
        if (!$facility) {
            throw new \InvalidArgumentException("Fasilitas tidak ditemukan.");
        }

        return $this->facilityRepository->delete($facility);
    }
}
