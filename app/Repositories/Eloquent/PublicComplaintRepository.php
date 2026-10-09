<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\PublicComplaint;
use App\Repositories\Contracts\PublicComplaintRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PublicComplaintRepository implements PublicComplaintRepositoryInterface
{
    public function getPaginatedForAdmin(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = PublicComplaint::query()->with('property');

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['category']) && $filters['category'] !== 'all') {
            $query->where('category', $filters['category']);
        }

        if (!empty($filters['property_id']) && $filters['property_id'] !== 'all') {
            $query->where('property_id', $filters['property_id']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('reporter_name', 'like', "%{$search}%")
                  ->orWhere('reporter_phone', 'like', "%{$search}%")
                  ->orWhere('room_number', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function findById(string $id): ?PublicComplaint
    {
        return PublicComplaint::with('property')->find($id);
    }

    public function create(array $data): PublicComplaint
    {
        return PublicComplaint::create($data);
    }

    public function update(PublicComplaint $complaint, array $data): PublicComplaint
    {
        $complaint->update($data);
        return $complaint->fresh(['property']);
    }
}
