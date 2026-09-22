<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use App\Repositories\Contracts\TenancyRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly TenancyRepositoryInterface $tenancyRepository
    ) {}

    public function getPaginatedInvoices(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->invoiceRepository->getPaginated($filters, $perPage);
    }

    public function getInvoiceById(string $id): Invoice
    {
        $invoice = $this->invoiceRepository->findById($id);
        if (!$invoice) {
            throw ValidationException::withMessages(['invoice' => ['Data tagihan tidak ditemukan.']]);
        }
        return $invoice;
    }

    public function createManualInvoice(array $data): Invoice
    {
        $tenancy = $this->tenancyRepository->findById($data['tenancy_id']);
        if (!$tenancy) {
            throw ValidationException::withMessages(['tenancy_id' => ['Data penyewa tidak valid.']]);
        }

        $items = $data['items'] ?? [];
        if (empty($items)) {
            throw ValidationException::withMessages(['items' => ['Tagihan wajib memiliki minimal 1 rincian item.']]);
        }

        $totalAmount = 0.0;
        foreach ($items as $item) {
            $totalAmount += (float) $item['amount'];
        }

        $period = $data['period'];
        $invoiceNumber = $this->invoiceRepository->generateInvoiceNumber($period);

        $invoiceData = [
            'tenancy_id' => $tenancy->id,
            'invoice_number' => $invoiceNumber,
            'period' => $period,
            'total_amount' => $totalAmount,
            'paid_amount' => 0,
            'status' => 'belum_bayar',
            'due_date' => $data['due_date'],
        ];

        return $this->invoiceRepository->create($invoiceData, $items);
    }

    public function updateStatus(string $id, string $status): Invoice
    {
        $invoice = $this->getInvoiceById($id);

        $validStatuses = [
            'belum_bayar',
            'sebagian_dibayar',
            'menunggu_verifikasi',
            'lunas',
            'terlambat',
            'dibatalkan',
        ];

        if (!in_array($status, $validStatuses, true)) {
            throw ValidationException::withMessages(['status' => ['Status tagihan tidak valid.']]);
        }

        return $this->invoiceRepository->updateStatus($invoice, $status);
    }
}
