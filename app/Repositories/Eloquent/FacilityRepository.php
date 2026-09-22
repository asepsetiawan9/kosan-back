<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Facility;
use App\Repositories\Contracts\FacilityRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class FacilityRepository implements FacilityRepositoryInterface
{
    public function all(): Collection
    {
        return Facility::orderBy('category')->orderBy('name')->get();
    }

    public function findById(string $id): ?Facility
    {
        return Facility::find($id);
    }

    public function create(array $data): Facility
    {
        return Facility::create($data);
    }

    public function update(Facility $facility, array $data): Facility
    {
        $facility->update($data);
        return $facility->fresh();
    }

    public function delete(Facility $facility): bool
    {
        return (bool) $facility->delete();
    }
}
