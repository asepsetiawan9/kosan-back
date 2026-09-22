<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContractResource;
use App\Models\Tenancy;
use App\Repositories\Contracts\ContractRepositoryInterface;
use App\Services\ContractPdfService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ContractController extends Controller
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractPdfService $pdfService
    ) {}

    /**
     * Generate or regenerate a draft contract for a tenancy.
     */
    public function generate(string $tenancyId): JsonResponse
    {
        $tenancy = Tenancy::with(['room.facilities', 'user'])->findOrFail($tenancyId);

        try {
            $contract = $this->pdfService->generateDraft($tenancy);

            return response()->json([
                'message' => 'Draf kontrak sewa berhasil dibuat.',
                'data' => new ContractResource($contract),
            ], 201);
        } catch (DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get the latest contract for a tenancy.
     */
    public function show(string $tenancyId): JsonResponse
    {
        $contract = $this->contractRepository->findLatestByTenancyId($tenancyId);

        if (!$contract) {
            return response()->json([
                'message' => 'Belum ada draf kontrak untuk sewa ini.',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'data' => new ContractResource($contract),
        ]);
    }

    /**
     * Stream the contract PDF file via temporary signed URL.
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
