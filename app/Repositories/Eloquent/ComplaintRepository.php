<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Complaint;
use App\Repositories\Contracts\ComplaintRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ComplaintRepository implements ComplaintRepositoryInterface
{
    public function getByTenancyId(string $tenancyId, array $filters = []): Collection
    {
        $query = Complaint::query()->where('tenancy_id', $tenancyId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function getPaginatedForAdmin(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Complaint::query()
            ->with(['tenancy.room', 'tenancy.user']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhereHas('tenancy', function ($tq) use ($search) {
                        $tq->where('tenant_name', 'like', "%{$search}%")
                            ->orWhere('tenant_phone', 'like', "%{$search}%")
                            ->orWhereHas('room', function ($rq) use ($search) {
                                $rq->where('room_number', 'like', "%{$search}%")
                                    ->orWhere('name', 'like', "%{$search}%");
                            });
                    });
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function findById(string $id): ?Complaint
    {
        return Complaint::query()
            ->with(['tenancy.room', 'tenancy.user'])
            ->find($id);
    }

    public function create(array $data): Complaint
    {
        return Complaint::create($data);
    }

    public function update(Complaint $complaint, array $data): Complaint
    {
        $complaint->update($data);
        return $complaint->fresh(['tenancy.room', 'tenancy.user']);
    }
}
