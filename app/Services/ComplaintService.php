<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Complaint;
use App\Models\Tenancy;
use App\Models\User;
use App\Repositories\Contracts\ComplaintRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ComplaintService
{
    public function __construct(
        protected ComplaintRepositoryInterface $complaintRepository
    ) {}

    /**
     * Dapatkan daftar aduan untuk penyewa yang sedang login.
     *
     * @return Collection<int, Complaint>
     */
    public function getComplaintsForTenant(User $user, array $filters = []): Collection
    {
        // Ambil seluruh tenancy milik penyewa (aktif maupun pasca sewa)
        $tenancyIds = $user->tenancies()->pluck('id')->toArray();

        if (empty($tenancyIds)) {
            return new Collection();
        }

        return Complaint::query()
            ->with(['tenancy.room'])
            ->whereIn('tenancy_id', $tenancyIds)
            ->when(!empty($filters['status']), fn($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['category']), fn($q) => $q->where('category', $filters['category']))
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Ajukan aduan baru oleh penyewa.
     */
    public function createComplaint(User $user, array $data, ?UploadedFile $photoFile = null): Complaint
    {
        // Cari tenancy berstatus aktif
        $activeTenancy = $user->tenancies()
            ->with('room')
            ->where('status', 'aktif')
            ->first();

        if (!$activeTenancy) {
            throw ValidationException::withMessages([
                'tenancy' => ['Anda tidak memiliki masa sewa aktif untuk mengajukan aduan fasilitas.'],
            ]);
        }

        $photoPath = null;
        if ($photoFile) {
            $extension = $photoFile->getClientOriginalExtension() ?: 'jpg';
            $fileName = Str::uuid()->toString() . '.' . $extension;
            $photoPath = Storage::disk('public')->putFileAs('complaints', $photoFile, $fileName);
        }

        $complaint = $this->complaintRepository->create([
            'tenancy_id' => $activeTenancy->id,
            'category' => $data['category'],
            'description' => $data['description'],
            'photo' => $photoPath,
            'status' => 'baru',
        ]);

        // Dispatch notifikasi WhatsApp ke admin
        $adminUser = User::where('role', 'admin')->first();
        $adminPhone = $adminUser?->phone ?? '081234567890';
        $roomName = $activeTenancy->room ? "Kamar {$activeTenancy->room->room_number}" : "Kamar Aktif";

        SendWhatsAppNotificationJob::dispatch(
            $adminPhone,
            "Tiket Aduan Baru Masuk!\nPenghuni: {$user->name} ({$roomName})\nKategori: {$complaint->category}\nKeluhan: {$complaint->description}",
            'new_complaint'
        );

        return $complaint->fresh(['tenancy.room']);
    }

    /**
     * Ambil aduan untuk panel admin dengan filter dan pagination.
     */
    public function getComplaintsForAdmin(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->complaintRepository->getPaginatedForAdmin($filters, $perPage);
    }

    /**
     * Detail aduan berdasarkan ID.
     */
    public function findById(string $id): ?Complaint
    {
        return $this->complaintRepository->findById($id);
    }

    /**
     * Update status dan respon aduan oleh admin.
     */
    public function updateStatusByAdmin(string $complaintId, array $data): Complaint
    {
        $complaint = $this->complaintRepository->findById($complaintId);

        if (!$complaint) {
            throw ValidationException::withMessages([
                'complaint' => ['Tiket aduan tidak ditemukan.'],
            ]);
        }

        $updateData = [];

        if (isset($data['status'])) {
            $updateData['status'] = $data['status'];
            if ($data['status'] === 'selesai' && !$complaint->resolved_at) {
                $updateData['resolved_at'] = now();
            }
        }

        if (array_key_exists('admin_response', $data)) {
            $updateData['admin_response'] = $data['admin_response'];
        }

        return $this->complaintRepository->update($complaint, $updateData);
    }
}
