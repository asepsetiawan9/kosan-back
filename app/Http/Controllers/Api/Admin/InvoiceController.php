<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceStatusRequest;
use App\Http\Resources\InvoiceResource;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoiceService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->only(['status', 'period', 'tenancy_id']);
        $invoices = $this->invoiceService->getPaginatedInvoices($filters, (int) $request->get('per_page', 15));

        return InvoiceResource::collection($invoices);
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->invoiceService->createManualInvoice($request->validated());

        return response()->json([
            'message' => 'Tagihan berhasil dibuat.',
            'data' => new InvoiceResource($invoice),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $invoice = $this->invoiceService->getInvoiceById($id);

        return response()->json([
            'data' => new InvoiceResource($invoice),
        ]);
    }

    public function updateStatus(UpdateInvoiceStatusRequest $request, string $id): JsonResponse
    {
        $invoice = $this->invoiceService->updateStatus($id, $request->validated('status'));

        return response()->json([
            'message' => 'Status tagihan berhasil diperbarui.',
            'data' => new InvoiceResource($invoice),
        ]);
    }
}
