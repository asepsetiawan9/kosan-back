<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceRepository implements InvoiceRepositoryInterface
{
    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Invoice::with(['tenancy.room', 'items']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['period'])) {
            $query->where('period', $filters['period']);
        }

        if (!empty($filters['tenancy_id'])) {
            $query->where('tenancy_id', $filters['tenancy_id']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function findById(string $id): ?Invoice
    {
        return Invoice::with(['tenancy.room', 'items'])->find($id);
    }

    public function create(array $data, array $items = []): Invoice
    {
        return DB::transaction(function () use ($data, $items) {
            $invoice = Invoice::create($data);

            foreach ($items as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $item['description'],
                    'amount' => $item['amount'],
                    'item_type' => $item['item_type'] ?? 'sewa',
                ]);
            }

            return $invoice->fresh(['items', 'tenancy']);
        });
    }

    public function updateStatus(Invoice $invoice, string $status): Invoice
    {
        $data = ['status' => $status];
        if ($status === 'lunas') {
            $data['paid_amount'] = $invoice->total_amount;
        }

        $invoice->update($data);
        return $invoice->fresh(['items', 'tenancy']);
    }

    public function generateInvoiceNumber(string $period): string
    {
        $cleanedPeriod = str_replace('-', '', $period);
        $count = Invoice::where('period', $period)->count() + 1;
        $random = strtoupper(Str::random(3));
        return sprintf("INV-%s-%04d-%s", $cleanedPeriod, $count, $random);
    }
}
