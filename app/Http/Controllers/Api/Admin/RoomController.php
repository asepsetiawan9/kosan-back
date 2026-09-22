<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoomRequest;
use App\Http\Requests\UpdateRoomRequest;
use App\Http\Resources\RoomResource;
use App\Services\RoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoomController extends Controller
{
    public function __construct(
        private readonly RoomService $roomService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->only(['status', 'type', 'search']);
        $rooms = $this->roomService->getPaginatedRooms($filters, (int) $request->get('per_page', 15));

        return RoomResource::collection($rooms);
    }

    public function available(): AnonymousResourceCollection
    {
        $rooms = $this->roomService->getAvailableRooms();
        return RoomResource::collection($rooms);
    }

    public function store(StoreRoomRequest $request): JsonResponse
    {
        $data = $request->safe()->only(['room_number', 'name', 'type', 'base_price', 'description']);
        $facilityIds = $request->validated('facility_ids', []);
        $images = $request->validated('images', []);

        $room = $this->roomService->createRoom($data, $facilityIds, $images);

        return response()->json([
            'message' => 'Kamar berhasil dibuat.',
            'data' => new RoomResource($room),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $room = $this->roomService->getRoomById($id);

        return response()->json([
            'data' => new RoomResource($room),
        ]);
    }

    public function update(UpdateRoomRequest $request, string $id): JsonResponse
    {
        $data = $request->safe()->only(['room_number', 'name', 'type', 'base_price', 'description', 'status']);
        $facilityIds = $request->has('facility_ids') ? $request->validated('facility_ids') : null;

        $room = $this->roomService->updateRoom($id, $data, $facilityIds);

        return response()->json([
            'message' => 'Data kamar berhasil diperbarui.',
            'data' => new RoomResource($room),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->roomService->deleteRoom($id);

        return response()->json([
            'message' => 'Kamar berhasil dihapus.',
        ]);
    }
}
