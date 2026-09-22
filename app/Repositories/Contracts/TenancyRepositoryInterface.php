<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Tenancy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TenancyRepositoryInterface
{
    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator;
    public function findById(string $id): ?Tenancy;
    public function create(array $data): Tenancy;
    public function update(Tenancy $tenancy, array $data): Tenancy;
    public function hasActiveTenancy(string $roomId): bool;
}
