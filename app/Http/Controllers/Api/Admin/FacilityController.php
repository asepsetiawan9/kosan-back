<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFacilityRequest;
use App\Http\Resources\FacilityResource;
use App\Services\FacilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FacilityController extends Controller
{
    public function __construct(
        private readonly FacilityService $facilityService
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $facilities = $this->facilityService->getAllFacilities();
        return FacilityResource::collection($facilities);
    }

    public function store(StoreFacilityRequest $request): JsonResponse
    {
        $facility = $this->facilityService->createFacility($request->validated());

        return response()->json([
            'message' => 'Fasilitas berhasil ditambahkan.',
            'data' => new FacilityResource($facility),
        ], 201);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->facilityService->deleteFacility($id);

        return response()->json([
            'message' => 'Fasilitas berhasil dihapus.',
        ]);
    }
}
