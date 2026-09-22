<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Booking;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface BookingRepositoryInterface
{
    public function findById(string $id): ?Booking;

    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function create(array $data): Booking;

    public function update(Booking $booking, array $data): Booking;

    public function getStalePendingBookings(int $days = 3): Collection;

    public function cancelStalePendingBookings(int $days = 3): int;
}
