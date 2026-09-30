<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePropertyRequest;
use App\Http\Requests\UpdatePropertyRequest;
use App\Http\Resources\PropertyResource;
use App\Services\PropertyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PropertyController extends Controller
{
    public function __construct(
        private readonly PropertyService $propertyService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->only(['search', 'city']);

        if ($request->boolean('all')) {
            $properties = $this->propertyService->getAllProperties($filters);
            return PropertyResource::collection($properties);
        }

        $perPage = (int) $request->get('per_page', 15);
        $properties = $this->propertyService->getPaginatedProperties($filters, $perPage);

        return PropertyResource::collection($properties);
    }

    public function store(StorePropertyRequest $request): JsonResponse
    {
        $property = $this->propertyService->createProperty($request->validated());

        return response()->json([
            'message' => 'Properti berhasil ditambahkan.',
            'data' => new PropertyResource($property),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $property = $this->propertyService->getPropertyById($id);

        return response()->json([
            'data' => new PropertyResource($property),
        ]);
    }

    public function update(UpdatePropertyRequest $request, string $id): JsonResponse
    {
        $property = $this->propertyService->updateProperty($id, $request->validated());

        return response()->json([
            'message' => 'Data properti berhasil diperbarui.',
            'data' => new PropertyResource($property),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->propertyService->deleteProperty($id);

        return response()->json([
            'message' => 'Properti berhasil dihapus.',
        ]);
    }
}
