<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePublicComplaintRequest;
use App\Http\Resources\PublicComplaintResource;
use App\Models\PublicComplaint;
use App\Services\PublicComplaintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicComplaintController extends Controller
{
    public function __construct(
        protected PublicComplaintService $service
    ) {}

    /**
     * Daftar seluruh aduan publik untuk admin (paginated & filterable).
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'category', 'property_id', 'search']);
        $perPage = (int) $request->input('per_page', 15);

        $complaints = $this->service->getPaginatedForAdmin($filters, $perPage);

        return response()->json([
            'data' => PublicComplaintResource::collection($complaints),
            'meta' => [
                'current_page' => $complaints->currentPage(),
                'last_page' => $complaints->lastPage(),
                'per_page' => $complaints->perPage(),
                'total' => $complaints->total(),
            ],
        ]);
    }

    /**
     * Detail aduan publik untuk admin.
     */
    public function show(string $id): JsonResponse
    {
        $complaint = PublicComplaint::with('property')->find($id);

        if (!$complaint) {
            return response()->json(['message' => 'Tiket aduan publik tidak ditemukan.'], 404);
        }

        return response()->json([
            'data' => new PublicComplaintResource($complaint),
        ]);
    }

    /**
     * Perbarui status penanganan aduan publik dan catat respon admin.
     */
    public function update(UpdatePublicComplaintRequest $request, string $id): JsonResponse
    {
        $complaint = PublicComplaint::find($id);

        if (!$complaint) {
            return response()->json(['message' => 'Tiket aduan publik tidak ditemukan.'], 404);
        }

        $updated = $this->service->updateStatus(
            $complaint,
            $request->input('status', $complaint->status),
            $request->input('admin_response')
        );

        return response()->json([
            'message' => 'Status aduan publik berhasil diperbarui.',
            'data' => new PublicComplaintResource($updated),
        ]);
    }
}
