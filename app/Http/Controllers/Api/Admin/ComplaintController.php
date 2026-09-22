<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateComplaintRequest;
use App\Http\Resources\ComplaintResource;
use App\Services\ComplaintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComplaintController extends Controller
{
    public function __construct(
        protected ComplaintService $complaintService
    ) {}

    /**
     * Daftar seluruh aduan penghuni untuk admin (paginated & filterable).
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'category', 'search']);
        $perPage = (int) $request->input('per_page', 15);

        $complaints = $this->complaintService->getComplaintsForAdmin($filters, $perPage);

        return response()->json([
            'data' => ComplaintResource::collection($complaints),
            'meta' => [
                'current_page' => $complaints->currentPage(),
                'last_page' => $complaints->lastPage(),
                'per_page' => $complaints->perPage(),
                'total' => $complaints->total(),
            ],
        ]);
    }

    /**
     * Detail aduan untuk admin.
     */
    public function show(string $id): JsonResponse
    {
        $complaint = $this->complaintService->findById($id);

        if (!$complaint) {
            return response()->json(['message' => 'Tiket aduan tidak ditemukan.'], 404);
        }

        return response()->json([
            'data' => new ComplaintResource($complaint),
        ]);
    }

    /**
     * Perbarui status penanganan aduan dan catat respon admin.
     */
    public function update(UpdateComplaintRequest $request, string $id): JsonResponse
    {
        $complaint = $this->complaintService->updateStatusByAdmin(
            $id,
            $request->only(['status', 'admin_response'])
        );

        return response()->json([
            'message' => 'Status tiket aduan berhasil diperbarui.',
            'data' => new ComplaintResource($complaint),
        ]);
    }
}
