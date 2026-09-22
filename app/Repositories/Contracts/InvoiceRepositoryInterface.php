<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Invoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface InvoiceRepositoryInterface
{
    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator;
    public function findById(string $id): ?Invoice;
    public function create(array $data, array $items = []): Invoice;
    public function updateStatus(Invoice $invoice, string $status): Invoice;
    public function generateInvoiceNumber(string $period): string;
}
