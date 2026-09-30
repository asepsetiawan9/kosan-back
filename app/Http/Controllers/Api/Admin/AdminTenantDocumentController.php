<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyDocumentRequest;
use App\Http\Resources\TenantDocumentResource;
use App\Models\TenantDocument;
use App\Models\User;
use App\Services\TenantDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminTenantDocumentController extends Controller
{
    public function __construct(
        protected TenantDocumentService $documentService
    ) {}

    /**
     * Tampilkan seluruh berkas identitas penghuni tertentu untuk inspeksi admin.
     */
    public function index(Request $request, string $userId): JsonResponse
    {
        $tenant = User::find($userId);

        if (!$tenant) {
            return response()->json(['message' => 'Penghuni tidak ditemukan.'], 404);
        }

        $documents = $this->documentService->getDocumentsByUser($userId);

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'nik' => $tenant->nik,
                'phone' => $tenant->phone,
                'email' => $tenant->email,
            ],
            'data' => TenantDocumentResource::collection($documents),
        ]);
    }

    /**
     * Verifikasi atau tolak keabsahan dokumen identitas penghuni.
     */
    public function verify(VerifyDocumentRequest $request, string $userId, string $id): JsonResponse
    {
        $admin = $request->user();
        $isVerified = (bool) $request->validated('is_verified');
        $notes = $request->validated('notes');

        $document = $this->documentService->findById($id);

        if (!$document || $document->user_id !== $userId) {
            return response()->json(['message' => 'Dokumen tidak ditemukan untuk penghuni ini.'], 404);
        }

        $updatedDoc = $this->documentService->verifyDocument($id, $admin, $isVerified, $notes);

        return response()->json([
            'message' => $isVerified ? 'Dokumen berhasil diverifikasi sah.' : 'Status verifikasi dokumen diperbarui.',
            'data' => new TenantDocumentResource($updatedDoc),
        ]);
    }

    /**
     * Stream berkas privat penghuni via Temporary Signed URL untuk admin.
     */
    public function stream(Request $request, string $userId, string $id): BinaryFileResponse|JsonResponse
    {
        $document = TenantDocument::find($id);

        if (!$document || $document->user_id !== $userId) {
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

    /**
     * Daftar seluruh penghuni beserta ringkasan NIK dan status kelengkapan dokumen.
     */
    public function tenants(Request $request): JsonResponse
    {
        $query = User::where('role', 'penyewa')
            ->with(['tenancies.room', 'documents']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('nik', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $tenants = $query->latest()->paginate(15);

        return response()->json($tenants);
    }
}
