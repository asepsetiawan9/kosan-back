<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TenantDocument;
use App\Models\User;
use App\Repositories\Contracts\TenantDocumentRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TenantDocumentService
{
    public function __construct(
        protected TenantDocumentRepositoryInterface $documentRepository
    ) {}

    /**
     * @return Collection<int, TenantDocument>
     */
    public function getDocumentsByUser(string $userId): Collection
    {
        return $this->documentRepository->findByUser($userId);
    }

    public function findById(string $id): ?TenantDocument
    {
        return $this->documentRepository->findById($id);
    }

    /**
     * Upload dan simpan dokumen identitas penghuni (KTP, KK, SIM, dll) di storage privat.
     */
    public function uploadDocument(User $user, UploadedFile $file, string $type): TenantDocument
    {
        $validTypes = ['ktp', 'kk', 'sim', 'lainnya'];
        if (!in_array($type, $validTypes, true)) {
            throw ValidationException::withMessages([
                'document_type' => ['Tipe dokumen tidak valid. Pilihan: ktp, kk, sim, lainnya.'],
            ]);
        }

        // Cek apakah dokumen tipe ini sudah pernah diunggah dan terverifikasi
        $existing = $this->documentRepository->findByUserAndType($user->id, $type);
        if ($existing && $existing->is_verified) {
            throw ValidationException::withMessages([
                'document_type' => ['Dokumen tipe ini sudah diverifikasi oleh pengelola dan tidak dapat diubah.'],
            ]);
        }

        // Sanitasi dan simpan file di disk privat
        $extension = $file->getClientOriginalExtension();
        $randomFileName = Str::uuid()->toString() . '.' . ($extension ?: 'jpg');
        $storageDir = "private/tenant-documents/{$user->id}";
        $storedPath = Storage::disk('local')->putFileAs($storageDir, $file, $randomFileName);

        // Jika ada file lama yang belum diverifikasi, hapus file lamanya
        if ($existing) {
            if ($existing->file_path && Storage::disk('local')->exists($existing->file_path)) {
                Storage::disk('local')->delete($existing->file_path);
            }

            return $this->documentRepository->update($existing, [
                'file_path' => $storedPath,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'is_verified' => false,
                'verified_at' => null,
                'verified_by' => null,
                'notes' => null,
            ]);
        }

        return $this->documentRepository->create([
            'user_id' => $user->id,
            'document_type' => $type,
            'file_path' => $storedPath,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'is_verified' => false,
        ]);
    }

    /**
     * Generate Temporary Signed URL untuk preview berkas secara aman (5 menit).
     */
    public function getSignedStreamUrl(TenantDocument $document, bool $isAdmin = false, int $expiryMinutes = 5): string
    {
        if ($isAdmin) {
            return URL::temporarySignedRoute(
                'admin.tenants.documents.stream',
                now()->addMinutes($expiryMinutes),
                [
                    'userId' => $document->user_id,
                    'id' => $document->id,
                ]
            );
        }

        return URL::temporarySignedRoute(
            'tenant.documents.stream',
            now()->addMinutes($expiryMinutes),
            [
                'id' => $document->id,
            ]
        );
    }

    /**
     * Verifikasi dokumen oleh Admin.
     */
    public function verifyDocument(string $id, User $admin, bool $isVerified, ?string $notes = null): TenantDocument
    {
        $document = $this->documentRepository->findById($id);

        if (!$document) {
            throw ValidationException::withMessages([
                'document' => ['Dokumen identitas tidak ditemukan.'],
            ]);
        }

        return $this->documentRepository->update($document, [
            'is_verified' => $isVerified,
            'verified_at' => $isVerified ? now() : null,
            'verified_by' => $isVerified ? $admin->id : null,
            'notes' => $notes,
        ]);
    }

    /**
     * Hapus dokumen fisik dan record.
     */
    public function deleteDocument(TenantDocument $document, User $actor): bool
    {
        // Penghuni tidak boleh menghapus dokumen yang sudah diverifikasi
        if (!$actor->isAdmin() && $document->is_verified) {
            throw ValidationException::withMessages([
                'document' => ['Dokumen yang telah diverifikasi sah tidak dapat dihapus.'],
            ]);
        }

        // Penghuni hanya bisa menghapus dokumen milik sendiri
        if (!$actor->isAdmin() && $document->user_id !== $actor->id) {
            throw ValidationException::withMessages([
                'document' => ['Anda tidak memiliki akses untuk menghapus dokumen ini.'],
            ]);
        }

        if ($document->file_path && Storage::disk('local')->exists($document->file_path)) {
            Storage::disk('local')->delete($document->file_path);
        }

        return $this->documentRepository->delete($document);
    }

    /**
     * Update NIK penghuni dengan validasi 16 digit angka.
     */
    public function updateNik(User $user, string $nik): User
    {
        $cleanNik = trim($nik);

        if (!preg_match('/^[0-9]{16}$/', $cleanNik)) {
            throw ValidationException::withMessages([
                'nik' => ['Nomor Induk Kependudukan (NIK) wajib terdiri dari 16 digit angka.'],
            ]);
        }

        $nikExists = User::where('nik', $cleanNik)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($nikExists) {
            throw ValidationException::withMessages([
                'nik' => ['NIK ini sudah terdaftar pada pengguna lain.'],
            ]);
        }

        $user->update(['nik' => $cleanNik]);

        return $user->fresh();
    }
}
