<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\TenantDocument;
use App\Repositories\Contracts\TenantDocumentRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class TenantDocumentRepository implements TenantDocumentRepositoryInterface
{
    /**
     * @return Collection<int, TenantDocument>
     */
    public function findByUser(string $userId): Collection
    {
        return TenantDocument::with(['verifier'])
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function findByUserAndType(string $userId, string $type): ?TenantDocument
    {
        return TenantDocument::where('user_id', $userId)
            ->where('document_type', $type)
            ->latest()
            ->first();
    }

    public function findById(string $id): ?TenantDocument
    {
        return TenantDocument::with(['user', 'verifier'])->find($id);
    }

    public function create(array $data): TenantDocument
    {
        return TenantDocument::create($data);
    }

    public function update(TenantDocument $document, array $data): TenantDocument
    {
        $document->update($data);
        return $document->fresh();
    }

    public function delete(TenantDocument $document): bool
    {
        return (bool) $document->delete();
    }
}
