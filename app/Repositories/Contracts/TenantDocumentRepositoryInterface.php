<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\TenantDocument;
use Illuminate\Database\Eloquent\Collection;

interface TenantDocumentRepositoryInterface
{
    /**
     * @return Collection<int, TenantDocument>
     */
    public function findByUser(string $userId): Collection;

    public function findByUserAndType(string $userId, string $type): ?TenantDocument;

    public function findById(string $id): ?TenantDocument;

    public function create(array $data): TenantDocument;

    public function update(TenantDocument $document, array $data): TenantDocument;

    public function delete(TenantDocument $document): bool;
}
