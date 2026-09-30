<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Property;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface PropertyRepositoryInterface
{
    public function getAll(array $filters = []): Collection;
    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator;
    public function findById(string $id): ?Property;
    public function create(array $data): Property;
    public function update(Property $property, array $data): Property;
    public function delete(Property $property): bool;
}
