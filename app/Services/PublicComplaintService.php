<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\PublicComplaint;
use App\Models\User;
use App\Repositories\Contracts\PublicComplaintRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PublicComplaintService
{
    public function __construct(
        protected PublicComplaintRepositoryInterface $complaintRepository
    ) {}

    /**
     * Submit aduan publik dari pengunjung/penghuni melalui halaman utama.
     *
     * @param array<string, mixed> $data
     * @param array<int, UploadedFile> $photoFiles
     */
    public function submitComplaint(array $data, array $photoFiles = []): PublicComplaint
    {
        $photoPaths = [];

        foreach ($photoFiles as $file) {
            if ($file instanceof UploadedFile) {
                $ext = $file->getClientOriginalExtension() ?: 'jpg';
                $fileName = Str::uuid()->toString() . '.' . $ext;
                $path = Storage::disk('public')->putFileAs('public-complaints', $file, $fileName);
                if ($path) {
                    $photoPaths[] = $path;
                }
            }
        }

        $data['photos'] = !empty($photoPaths) ? $photoPaths : null;
        $data['status'] = 'baru';

        $complaint = $this->complaintRepository->create($data);

        // Notifikasi WA ke Admin jika ada
        $this->notifyAdmins($complaint);

        return $complaint;
    }

    /**
     * Ambil data aduan publik paginated untuk panel admin.
     */
    public function getPaginatedForAdmin(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->complaintRepository->getPaginatedForAdmin($filters, $perPage);
    }

    /**
     * Update status & tanggapan admin atas aduan publik.
     */
    public function updateStatus(
        PublicComplaint $complaint,
        string $status,
        ?string $adminResponse = null
    ): PublicComplaint {
        $updateData = [
            'status' => $status,
        ];

        if ($adminResponse !== null) {
            $updateData['admin_response'] = $adminResponse;
        }

        if ($status === 'selesai' && !$complaint->resolved_at) {
            $updateData['resolved_at'] = now();
        }

        return $this->complaintRepository->update($complaint, $updateData);
    }

    /**
     * Notifikasi admin terkait adanya aduan publik baru masuk.
     */
    protected function notifyAdmins(PublicComplaint $complaint): void
    {
        try {
            $adminUser = User::where('role', 'admin')->first();
            $adminPhone = $adminUser?->wa_number ?? $adminUser?->phone ?? '081234567890';

            $categoryLabel = match ($complaint->category) {
                'fasilitas_rusak' => 'Fasilitas Rusak',
                'kebersihan' => 'Kebersihan Lingkungan',
                'keamanan' => 'Keamanan & Ketertiban',
                'air_listrik' => 'Air / Kelistrikan',
                default => 'Lainnya',
            };

            $message = sprintf(
                "📢 *ADUAN PUBLIK BARU MASUK*\n\n" .
                "• Pelapor: %s (%s)\n" .
                "• Kategori: %s\n" .
                "• Unit/Kamar: %s\n" .
                "• Keterangan: %s\n\n" .
                "Mohon periksa dashboard admin untuk menindaklanjuti.",
                $complaint->reporter_name,
                $complaint->reporter_phone,
                $categoryLabel,
                $complaint->room_number ?? 'Umum / Tidak Disebutkan',
                Str::limit($complaint->description, 100)
            );

            SendWhatsAppNotificationJob::dispatch(
                $adminPhone,
                $message,
                'new_public_complaint'
            );
        } catch (\Throwable $e) {
            Log::warning('Gagal mendispatch notifikasi WA aduan publik: ' . $e->getMessage());
        }
    }
}
