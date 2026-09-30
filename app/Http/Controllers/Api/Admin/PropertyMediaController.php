<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePropertyMediaRequest;
use App\Http\Resources\PropertyMediaResource;
use App\Services\PropertyMediaService;
use App\Services\PropertyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PropertyMediaController extends Controller
{
    public function __construct(
        private readonly PropertyMediaService $mediaService,
        private readonly PropertyService $propertyService
    ) {}

    /**
     * Daftar media foto dan video untuk suatu properti.
     */
    public function index(string $propertyId): AnonymousResourceCollection
    {
        $property = $this->propertyService->getPropertyById($propertyId);
        $media = $this->mediaService->getMediaByProperty($property->id);

        return PropertyMediaResource::collection($media);
    }

    /**
     * Unggah media foto atau video baru untuk properti.
     */
    public function store(StorePropertyMediaRequest $request, string $propertyId): JsonResponse
    {
        $property = $this->propertyService->getPropertyById($propertyId);
        $file = $request->file('file');
        $thumbnail = $request->file('thumbnail');

        $media = $this->mediaService->uploadMedia(
            $property,
            $file,
            $thumbnail,
            $request->validated()
        );

        return (new PropertyMediaResource($media))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Hapus media dari properti.
     */
    public function destroy(string $propertyId, string $mediaId): JsonResponse
    {
        $this->mediaService->deleteMedia($propertyId, $mediaId);

        return response()->json([
            'message' => 'Media properti berhasil dihapus.',
        ]);
    }

    /**
     * Toggle atau set status featured media (iklan utama).
     */
    public function toggleFeatured(Request $request, string $propertyId, string $mediaId): PropertyMediaResource
    {
        $isFeatured = $request->has('is_featured') ? (bool) $request->input('is_featured') : null;
        $media = $this->mediaService->toggleFeatured($propertyId, $mediaId, $isFeatured);

        return new PropertyMediaResource($media);
    }

    /**
     * Atur ulang urutan (reorder) media properti.
     */
    public function reorder(Request $request, string $propertyId): JsonResponse
    {
        $validated = $request->validate([
            'ordered_ids' => ['required', 'array'],
            'ordered_ids.*' => ['required', 'uuid'],
        ]);

        $this->mediaService->reorderMedia($propertyId, $validated['ordered_ids']);

        return response()->json([
            'message' => 'Urutan media berhasil diperbarui.',
        ]);
    }
}
