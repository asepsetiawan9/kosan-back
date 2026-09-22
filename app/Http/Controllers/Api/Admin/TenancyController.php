<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutTenancyRequest;
use App\Http\Requests\StoreTenancyRequest;
use App\Http\Resources\TenancyResource;
use App\Services\TenancyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TenancyController extends Controller
{
    public function __construct(
        private readonly TenancyService $tenancyService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->only(['status', 'room_id', 'search']);
        $tenancies = $this->tenancyService->getPaginatedTenancies($filters, (int) $request->get('per_page', 15));

        return TenancyResource::collection($tenancies);
    }

    public function store(StoreTenancyRequest $request): JsonResponse
    {
        $data = $request->safe()->except(['create_first_invoice']);
        $createFirstInvoice = $request->boolean('create_first_invoice', true);

        $tenancy = $this->tenancyService->createTenancy($data, $createFirstInvoice);

        return response()->json([
            'message' => 'Pendaftaran penyewa berhasil disimpan.',
            'data' => new TenancyResource($tenancy),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $tenancy = $this->tenancyService->getTenancyById($id);

        return response()->json([
            'data' => new TenancyResource($tenancy),
        ]);
    }

    public function checkout(CheckoutTenancyRequest $request, string $id): JsonResponse
    {
        $tenancy = $this->tenancyService->checkout($id, $request->validated());

        return response()->json([
            'message' => 'Proses checkout penyewa berhasil diselesaikan.',
            'data' => new TenancyResource($tenancy),
        ]);
    }
}
