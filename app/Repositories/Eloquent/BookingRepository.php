<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Booking;
use App\Repositories\Contracts\BookingRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class BookingRepository implements BookingRepositoryInterface
{
    public function findById(string $id): ?Booking
    {
        return Booking::with(['room.images', 'room.facilities'])->find($id);
    }

    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Booking::with(['room.images', 'room.facilities'])
            ->orderBy('created_at', 'desc');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['room_id'])) {
            $query->where('room_id', $filters['room_id']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                    ->orWhere('phone', 'like', $search)
                    ->orWhere('email', 'like', $search);
            });
        }

        return $query->paginate($perPage);
    }

    public function create(array $data): Booking
    {
        return Booking::create($data);
    }

    public function update(Booking $booking, array $data): Booking
    {
        $booking->update($data);
        return $booking->fresh(['room.images', 'room.facilities']);
    }

    public function getStalePendingBookings(int $days = 3): Collection
    {
        return Booking::where('status', 'menunggu')
            ->where(function ($q) use ($days) {
                $q->where('expires_at', '<=', now())
                    ->orWhere('created_at', '<=', now()->subDays($days));
            })
            ->get();
    }

    public function cancelStalePendingBookings(int $days = 3): int
    {
        return Booking::where('status', 'menunggu')
            ->where(function ($q) use ($days) {
                $q->where('expires_at', '<=', now())
                    ->orWhere('created_at', '<=', now()->subDays($days));
            })
            ->update([
                'status' => 'dibatalkan',
                'rejection_reason' => 'Kedaluwarsa otomatis setelah ' . $days . ' hari tanpa konfirmasi.',
                'updated_at' => now(),
            ]);
    }
}
