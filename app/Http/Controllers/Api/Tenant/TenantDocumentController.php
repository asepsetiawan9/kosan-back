<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTenantDocumentRequest;
use App\Http\Requests\UpdateNikRequest;
use App\Http\Resources\TenantDocumentResource;
use App\Models\TenantDocument;
use App\Services\TenantDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TenantDocumentController extends Controller
{
    public function __construct(
        protected TenantDocumentService $documentService
    ) {}

    /**
     * Tampilkan seluruh berkas identitas milik penghuni login.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $documents = $this->documentService->getDocumentsByUser($user->id);

        return response()->json([
            'data' => TenantDocumentResource::collection($documents),
        ]);
    }

    /**
     * Unggah berkas identitas baru (KTP, KK, SIM, dll).
     */
    public function store(StoreTenantDocumentRequest $request): JsonResponse
    {
        $user = $request->user();
        $type = $request->validated('document_type');
        $file = $request->file('file');

        $document = $this->documentService->uploadDocument($user, $file, $type);

        return response()->json([
            'message' => 'Berkas identitas berhasil diunggah dan siap diverifikasi pengelola.',
            'data' => new TenantDocumentResource($document),
        ], 201);
    }

    /**
     * Perbarui NIK penghuni (16 digit).
     */
    public function updateNik(UpdateNikRequest $request): JsonResponse
    {
        $user = $request->user();
        $nik = $request->validated('nik');

        $updatedUser = $this->documentService->updateNik($user, $nik);

        return response()->json([
            'message' => 'Nomor Induk Kependudukan (NIK) berhasil disimpan.',
            'data' => [
                'id' => $updatedUser->id,
                'name' => $updatedUser->name,
                'nik' => $updatedUser->nik,
            ],
        ]);
    }

    /**
     * Hapus berkas yang belum diverifikasi.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $document = $this->documentService->findById($id);

        if (!$document) {
            return response()->json([
                'message' => 'Dokumen tidak ditemukan.',
            ], 404);
        }

        $this->documentService->deleteDocument($document, $user);

        return response()->json([
            'message' => 'Berkas identitas berhasil dihapus.',
        ]);
    }

    /**
     * Stream berkas privat penghuni via Temporary Signed URL.
     */
    public function stream(Request $request, string $id): BinaryFileResponse|JsonResponse
    {
        $document = TenantDocument::find($id);

        if (!$document) {
            return response()->json(['message' => 'Dokumen tidak ditemukan.'], 404);
        }

        $filePath = Storage::disk('local')->path($document->file_path);

        if (!file_exists($filePath)) {
            return response()->json(['message' => 'File fisik dokumen tidak ditemukan di server.'], 404);
        }

        $mimeType = $document->mime_type ?: mime_content_type($filePath);

        return response()->file($filePath, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . ($document->original_filename ?: basename($filePath)) . '"',
        ]);
    }
}
