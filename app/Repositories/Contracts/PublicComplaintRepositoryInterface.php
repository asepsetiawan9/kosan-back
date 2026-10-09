<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\PublicComplaint;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PublicComplaintRepositoryInterface
{
    /**
     * Ambil seluruh aduan publik untuk panel admin (dengan filter status, kategori, pencarian).
     */
    public function getPaginatedForAdmin(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Cari aduan publik berdasarkan ID.
     */
    public function findById(string $id): ?PublicComplaint;

    /**
     * Buat tiket aduan publik baru.
     */
    public function create(array $data): PublicComplaint;

    /**
     * Update data atau status aduan publik.
     */
    public function update(PublicComplaint $complaint, array $data): PublicComplaint;
}
