<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class TenantInvoiceService
{
    /**
     * Ambil seluruh tagihan milik penyewa yang sedang login.
     *
     * @return Collection<int, Invoice>
     */
    public function getInvoicesForTenant(User $user, array $filters = []): Collection
    {
        $tenancyIds = $user->tenancies()->pluck('id')->toArray();

        if (empty($tenancyIds)) {
            return new Collection();
        }

        return Invoice::query()
            ->with(['tenancy.room', 'items'])
            ->whereIn('tenancy_id', $tenancyIds)
            ->when(!empty($filters['status']), fn($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['period']), fn($q) => $q->where('period', $filters['period']))
            ->orderBy('due_date', 'desc')
            ->get();
    }

    /**
     * Ambil rincian satu invoice dengan verifikasi kepemilikan tenancy.
     */
    public function getInvoiceDetailForTenant(User $user, string $invoiceId): Invoice
    {
        $tenancyIds = $user->tenancies()->pluck('id')->toArray();

        $invoice = Invoice::query()
            ->with(['tenancy.room', 'items'])
            ->whereIn('tenancy_id', $tenancyIds)
            ->find($invoiceId);

        if (!$invoice) {
            throw ValidationException::withMessages([
                'invoice' => ['Tagihan tidak ditemukan atau bukan milik akun Anda.'],
            ]);
        }

        return $invoice;
    }
}
