<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Facility;
use Illuminate\Database\Eloquent\Collection;

interface FacilityRepositoryInterface
{
    public function all(): Collection;
    public function findById(string $id): ?Facility;
    public function create(array $data): Facility;
    public function update(Facility $facility, array $data): Facility;
    public function delete(Facility $facility): bool;
}
