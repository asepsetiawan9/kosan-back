<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Tenancy;
use App\Repositories\Contracts\TenancyRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TenancyRepository implements TenancyRepositoryInterface
{
    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Tenancy::with(['room', 'invoices', 'user']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['room_id'])) {
            $query->where('room_id', $filters['room_id']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('tenant_name', 'like', "%{$search}%")
                    ->orWhere('tenant_phone', 'like', "%{$search}%")
                    ->orWhere('tenant_email', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function findById(string $id): ?Tenancy
    {
        return Tenancy::with(['room', 'invoices.items', 'user'])->find($id);
    }

    public function create(array $data): Tenancy
    {
        return Tenancy::create($data);
    }

    public function update(Tenancy $tenancy, array $data): Tenancy
    {
        $tenancy->update($data);
        return $tenancy->fresh(['room', 'invoices']);
    }

    public function hasActiveTenancy(string $roomId): bool
    {
        return Tenancy::where('room_id', $roomId)->where('status', 'aktif')->exists();
    }
}
