<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicPropertyResource;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublicPropertyController extends Controller
{
    /**
     * List properti kosan untuk iklan & katalog publik.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Property::with([
            'featuredMedia',
            'media',
            'rooms' => function ($q) {
                $q->where('status', '!=', 'maintenance')
                    ->with(['facilities', 'primaryImage', 'images']);
            },
        ]);

        if ($request->filled('search')) {
            $search = '%' . $request->query('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                    ->orWhere('address', 'like', $search)
                    ->orWhere('city', 'like', $search);
            });
        }

        if ($request->filled('city')) {
            $query->where('city', $request->query('city'));
        }

        $properties = $query->orderBy('name', 'asc')->paginate(12);

        return PublicPropertyResource::collection($properties);
    }

    /**
     * Detail properti + kamar tersedia + media video & foto + lokasi maps.
     */
    public function show(string $id): PublicPropertyResource|JsonResponse
    {
        $property = Property::with([
            'featuredMedia',
            'media',
            'rooms' => function ($q) {
                $q->where('status', '!=', 'maintenance')
                    ->with(['facilities', 'primaryImage', 'images']);
            },
        ])->find($id);

        if (!$property) {
            return response()->json([
                'message' => 'Properti tidak ditemukan.',
            ], 404);
        }

        return new PublicPropertyResource($property);
    }
}
