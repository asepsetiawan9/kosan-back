<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Contract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ContractRepositoryInterface
{
    /**
     * Get paginated contracts with optional filters.
     *
     * @param array<string, mixed> $filters
     */
    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Find contract by UUID.
     */
    public function findById(string $id): ?Contract;

    /**
     * Find latest contract by tenancy ID.
     */
    public function findLatestByTenancyId(string $tenancyId): ?Contract;

    /**
     * Create a contract record.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): Contract;

    /**
     * Update an existing contract.
     *
     * @param array<string, mixed> $data
     */
    public function update(Contract $contract, array $data): bool;
}
