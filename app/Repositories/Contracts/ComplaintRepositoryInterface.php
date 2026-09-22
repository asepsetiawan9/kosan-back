<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Complaint;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ComplaintRepositoryInterface
{
    /**
     * Ambil aduan milik tenancy tertentu (penghuni).
     *
     * @return Collection<int, Complaint>
     */
    public function getByTenancyId(string $tenancyId, array $filters = []): Collection;

    /**
     * Ambil seluruh aduan untuk panel admin (dengan filter status & kategori).
     */
    public function getPaginatedForAdmin(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Cari aduan berdasarkan ID.
     */
    public function findById(string $id): ?Complaint;

    /**
     * Buat tiket aduan baru.
     */
    public function create(array $data): Complaint;

    /**
     * Update data atau status aduan.
     */
    public function update(Complaint $complaint, array $data): Complaint;
}
