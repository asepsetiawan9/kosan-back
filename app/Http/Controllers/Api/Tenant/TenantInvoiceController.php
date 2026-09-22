<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\TenantInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantInvoiceController extends Controller
{
    public function __construct(
        protected TenantInvoiceService $invoiceService
    ) {}

    /**
     * Daftar tagihan milik penyewa.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'period']);
        $invoices = $this->invoiceService->getInvoicesForTenant($request->user(), $filters);

        return response()->json([
            'data' => InvoiceResource::collection($invoices),
        ]);
    }

    /**
     * Detail rincian tagihan (terproteksi dengan InvoicePolicy).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $invoice = Invoice::with(['tenancy.room', 'items'])->findOrFail($id);

        // Verifikasi kepemilikan via InvoicePolicy
        $this->authorize('view', $invoice);

        return response()->json([
            'data' => new InvoiceResource($invoice),
        ]);
    }
}
