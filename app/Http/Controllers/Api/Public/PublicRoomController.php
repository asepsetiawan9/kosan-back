<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicRoomResource;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublicRoomController extends Controller
{
    /**
     * Listing kamar kosong untuk publik & landing page.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Room::with(['images', 'primaryImage', 'facilities'])
            ->where('status', 'kosong');

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('min_price')) {
            $query->where('base_price', '>=', (float) $request->query('min_price'));
        }

        if ($request->filled('max_price')) {
            $query->where('base_price', '<=', (float) $request->query('max_price'));
        }

        if ($request->filled('search')) {
            $search = '%' . $request->query('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                    ->orWhere('room_number', 'like', $search)
                    ->orWhere('description', 'like', $search);
            });
        }

        if ($request->filled('facility_ids')) {
            $facilityIds = is_array($request->query('facility_ids'))
                ? $request->query('facility_ids')
                : explode(',', (string) $request->query('facility_ids'));

            foreach ($facilityIds as $facId) {
                $trimmed = trim($facId);
                if (!empty($trimmed)) {
                    $query->whereHas('facilities', function ($q) use ($trimmed) {
                        $q->where('facilities.id', $trimmed);
                    });
                }
            }
        }

        $rooms = $query->orderBy('base_price', 'asc')->paginate(12);

        return PublicRoomResource::collection($rooms);
    }

    /**
     * Detail kamar publik (hanya kamar yang tidak sedang dalam maintenance).
     */
    public function show(string $id): PublicRoomResource|JsonResponse
    {
        $room = Room::with(['images', 'primaryImage', 'facilities'])
            ->where('id', $id)
            ->where('status', '!=', 'maintenance')
            ->first();

        if (!$room) {
            return response()->json([
                'message' => 'Kamar tidak ditemukan atau sedang dalam perbaikan.',
            ], 404);
        }

        return new PublicRoomResource($room);
    }
}
