<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreComplaintRequest;
use App\Http\Resources\ComplaintResource;
use App\Models\Complaint;
use App\Services\ComplaintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantComplaintController extends Controller
{
    public function __construct(
        protected ComplaintService $complaintService
    ) {}

    /**
     * Riwayat tiket aduan milik penyewa.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'category']);
        $complaints = $this->complaintService->getComplaintsForTenant($request->user(), $filters);

        return response()->json([
            'data' => ComplaintResource::collection($complaints),
        ]);
    }

    /**
     * Ajukan tiket aduan baru.
     */
    public function store(StoreComplaintRequest $request): JsonResponse
    {
        // Pastikan akun memiliki hak membuat aduan (wajib sewa aktif)
        $this->authorize('create', Complaint::class);

        $complaint = $this->complaintService->createComplaint(
            $request->user(),
            $request->only(['category', 'description']),
            $request->file('photo')
        );

        return response()->json([
            'message' => 'Tiket aduan berhasil dikirim. Pengelola kos telah diberitahu via WhatsApp.',
            'data' => new ComplaintResource($complaint),
        ], 201);
    }
}
