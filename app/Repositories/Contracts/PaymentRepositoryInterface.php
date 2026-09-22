<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface PaymentRepositoryInterface
{
    /**
     * Get paginated payments with optional filtering.
     *
     * @param array<string, mixed> $filters
     */
    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Find payment by ID.
     */
    public function findById(string $id): ?Payment;

    /**
     * Find payment by gateway transaction or order ID.
     */
    public function findByGatewayTransactionId(string $provider, string $transactionId): ?Payment;

    /**
     * Create a new payment record.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): Payment;

    /**
     * Update an existing payment.
     *
     * @param array<string, mixed> $data
     */
    public function update(Payment $payment, array $data): bool;

    /**
     * Get payments for an invoice.
     */
    public function getByInvoiceId(string $invoiceId): Collection;
}
