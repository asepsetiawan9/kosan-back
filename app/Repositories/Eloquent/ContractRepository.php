<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Contract;
use App\Repositories\Contracts\ContractRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ContractRepository implements ContractRepositoryInterface
{
    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Contract::with(['tenancy.room', 'tenancy.user']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['tenancy_id'])) {
            $query->where('tenancy_id', $filters['tenancy_id']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('contract_number', 'like', "%{$search}%")
                    ->orWhereHas('tenancy', function ($tq) use ($search) {
                        $tq->where('tenant_name', 'like', "%{$search}%")
                            ->orWhere('tenant_phone', 'like', "%{$search}%");
                    });
            });
        }

        return $query->latest()->paginate($perPage);
    }

    public function findById(string $id): ?Contract
    {
        return Contract::with(['tenancy.room', 'tenancy.user'])->find($id);
    }

    public function findLatestByTenancyId(string $tenancyId): ?Contract
    {
        return Contract::with(['tenancy.room', 'tenancy.user'])
            ->where('tenancy_id', $tenancyId)
            ->latest()
            ->first();
    }

    public function create(array $data): Contract
    {
        return Contract::create($data);
    }

    public function update(Contract $contract, array $data): bool
    {
        return $contract->update($data);
    }
}
