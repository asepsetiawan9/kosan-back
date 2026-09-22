<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Payment;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class PaymentRepository implements PaymentRepositoryInterface
{
    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Payment::query()->with([
            'invoice.tenancy.room',
            'invoice.tenancy.user',
            'verifier',
        ])->latest();

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['method'])) {
            $query->where('method', $filters['method']);
        }

        if (!empty($filters['invoice_id'])) {
            $query->where('invoice_id', $filters['invoice_id']);
        }

        if (!empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('gateway_transaction_id', 'like', "%{$search}%")
                    ->orWhereHas('invoice', function ($iq) use ($search) {
                        $iq->where('invoice_number', 'like', "%{$search}%")
                            ->orWhereHas('tenancy', function ($tq) use ($search) {
                                $tq->where('tenant_name', 'like', "%{$search}%")
                                    ->orWhere('tenant_phone', 'like', "%{$search}%");
                            });
                    });
            });
        }

        return $query->paginate($perPage);
    }

    public function findById(string $id): ?Payment
    {
        return Payment::with([
            'invoice.tenancy.room',
            'invoice.tenancy.user',
            'verifier',
        ])->find($id);
    }

    public function findByGatewayTransactionId(string $provider, string $transactionId): ?Payment
    {
        return Payment::with(['invoice.tenancy.user', 'invoice.tenancy.room'])
            ->where('gateway_provider', $provider)
            ->where('gateway_transaction_id', $transactionId)
            ->first();
    }

    public function create(array $data): Payment
    {
        return Payment::create($data);
    }

    public function update(Payment $payment, array $data): bool
    {
        return $payment->update($data);
    }

    public function getByInvoiceId(string $invoiceId): Collection
    {
        return Payment::with('verifier')
            ->where('invoice_id', $invoiceId)
            ->latest()
            ->get();
    }
}
