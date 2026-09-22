<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\SignContractRequest;
use App\Http\Resources\ContractResource;
use App\Models\Tenancy;
use App\Repositories\Contracts\ContractRepositoryInterface;
use App\Services\ContractPdfService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TenantContractController extends Controller
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractPdfService $pdfService
    ) {}

    /**
     * Get the authenticated tenant's active contract.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        // Get tenant's active tenancy
        $tenancy = Tenancy::where('user_id', $user->id)
            ->where('status', 'aktif')
            ->latest()
            ->first();

        if (!$tenancy) {
            // Fallback to any latest tenancy
            $tenancy = Tenancy::where('user_id', $user->id)->latest()->first();
        }

        if (!$tenancy) {
            return response()->json([
                'message' => 'Anda belum memiliki data sewa aktif.',
                'data' => null,
            ], 404);
        }

        $contract = $this->contractRepository->findLatestByTenancyId($tenancy->id);

        if (!$contract) {
            return response()->json([
                'message' => 'Draf kontrak sewa belum diterbitkan oleh pengelola kos.',
                'data' => null,
            ], 404);
        }

        Gate::authorize('view', $contract);

        return response()->json([
            'data' => new ContractResource($contract),
        ]);
    }

    /**
     * Digitally sign the contract.
     */
    public function sign(SignContractRequest $request): JsonResponse
    {
        $user = $request->user();

        $tenancy = Tenancy::where('user_id', $user->id)
            ->where('status', 'aktif')
            ->latest()
            ->first();

        if (!$tenancy) {
            return response()->json([
                'message' => 'Data sewa aktif tidak ditemukan.',
            ], 404);
        }

        $contract = $this->contractRepository->findLatestByTenancyId($tenancy->id);

        if (!$contract) {
            return response()->json([
                'message' => 'Dokumen kontrak sewa belum tersedia.',
            ], 404);
        }

        Gate::authorize('sign', $contract);

        try {
            $signedContract = $this->pdfService->applySignature(
                $contract,
                (string) $request->input('signature')
            );

            return response()->json([
                'message' => 'Kontrak sewa berhasil ditandatangani secara sah dan berkekuatan hukum.',
                'data' => new ContractResource($signedContract),
            ], 200);
        } catch (DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Stream the tenant's contract PDF file via temporary signed URL.
     */
    public function stream(string $id, Request $request): BinaryFileResponse|JsonResponse
    {
        $contract = $this->contractRepository->findById($id);

        if (!$contract) {
            return response()->json(['message' => 'Kontrak tidak ditemukan.'], 404);
        }

        $filePath = $this->pdfService->getActivePdfPath($contract);

        if (!file_exists($filePath)) {
            return response()->json(['message' => 'Berkas PDF belum selesai di-generate.'], 404);
        }

        $filename = "kontrak-sewa-{$contract->contract_number}.pdf";

        return response()->file($filePath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }
}
