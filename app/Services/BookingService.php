<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Booking;
use App\Models\Room;
use App\Models\Tenancy;
use App\Models\User;
use App\Repositories\Contracts\BookingRepositoryInterface;
use App\Repositories\Contracts\RoomRepositoryInterface;
use App\Repositories\Contracts\TenancyRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(
        protected BookingRepositoryInterface $bookingRepository,
        protected RoomRepositoryInterface $roomRepository,
        protected TenancyRepositoryInterface $tenancyRepository
    ) {}

    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->bookingRepository->getPaginated($filters, $perPage);
    }

    public function findById(string $id): ?Booking
    {
        return $this->bookingRepository->findById($id);
    }

    /**
     * Submit permohonan sewa/booking publik.
     */
    public function createBooking(array $data, UploadedFile $ktpFile): Booking
    {
        $room = $this->roomRepository->findById($data['room_id']);

        if (!$room || $room->status !== 'kosong') {
            throw ValidationException::withMessages([
                'room_id' => ['Kamar yang dipilih saat ini tidak tersedia atau sedang tidak dapat dipesan.'],
            ]);
        }

        // Sanitasi dan simpan file KTP secara privat
        $extension = $ktpFile->getClientOriginalExtension();
        $randomFileName = Str::uuid()->toString() . '.' . ($extension ?: 'jpg');
        $storedPath = Storage::disk('local')->putFileAs('private/ktp', $ktpFile, $randomFileName);

        $bookingData = [
            'room_id' => $data['room_id'],
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'ktp_file' => $storedPath,
            'requested_move_in' => $data['requested_move_in'],
            'status' => 'menunggu',
            'expires_at' => now()->addDays(3),
        ];

        return $this->bookingRepository->create($bookingData);
    }

    /**
     * Persetujuan booking oleh Admin dengan Pessimistic Locking anti-race condition.
     */
    public function approveBooking(string $bookingId): Booking
    {
        return DB::transaction(function () use ($bookingId) {
            $booking = $this->bookingRepository->findById($bookingId);

            if (!$booking) {
                throw ValidationException::withMessages([
                    'booking' => ['Permohonan booking tidak ditemukan.'],
                ]);
            }

            if ($booking->status !== 'menunggu') {
                throw ValidationException::withMessages([
                    'booking' => ["Booking ini sudah berstatus '{$booking->status}' dan tidak dapat disetujui kembali."],
                ]);
            }

            // Pessimistic Lock untuk mengunci baris kamar dari transaksi paralel lain
            $room = Room::where('id', $booking->room_id)->lockForUpdate()->first();

            if (!$room || $room->status !== 'kosong') {
                throw ValidationException::withMessages([
                    'room_id' => ['Kamar sudah tidak tersedia atau telah terisi oleh penyewa lain.'],
                ]);
            }

            // Cek atau buat user baru untuk calon penghuni
            $user = User::where('phone', $booking->phone)
                ->orWhere(function ($q) use ($booking) {
                    if (!empty($booking->email)) {
                        $q->where('email', $booking->email);
                    }
                })
                ->first();

            if (!$user) {
                $rawPhone = preg_replace('/[^0-9]/', '', $booking->phone);
                $cleanEmail = $booking->email ?: "penghuni.{$rawPhone}@kosanku.local";
                $defaultPassword = 'Kosan#' . substr($rawPhone, -4);

                $user = User::create([
                    'name' => $booking->name,
                    'email' => $cleanEmail,
                    'phone' => $booking->phone,
                    'password' => Hash::make($defaultPassword),
                    'role' => 'penyewa',
                    'must_change_password' => true,
                ]);
            }

            // Daftarkan Tenancy aktif
            $tenancy = Tenancy::create([
                'room_id' => $room->id,
                'user_id' => $user->id,
                'tenant_name' => $booking->name,
                'tenant_phone' => $booking->phone,
                'tenant_email' => $booking->email,
                'start_date' => $booking->requested_move_in,
                'billing_due_day' => 1,
                'deposit_amount' => $room->base_price,
                'deposit_status' => 'ditahan',
                'status' => 'aktif',
            ]);

            // Kunci kamar jadi terisi
            $room->update(['status' => 'terisi']);

            // Update status booking
            $updatedBooking = $this->bookingRepository->update($booking, [
                'status' => 'disetujui',
                'rejection_reason' => null,
            ]);

            // Dispatch asinkron notifikasi WhatsApp
            SendWhatsAppNotificationJob::dispatch(
                $booking->phone,
                "Halo {$booking->name}, permohonan booking kamar {$room->room_number} ({$room->name}) telah DISETUJUI. Akun portal Anda siap digunakan dengan No. HP {$booking->phone}. Silakan periksa dashboard untuk rincian sewa.",
                'booking_approved'
            );

            return $updatedBooking;
        });
    }

    /**
     * Penolakan booking oleh Admin.
     */
    public function rejectBooking(string $bookingId, string $reason): Booking
    {
        return DB::transaction(function () use ($bookingId, $reason) {
            $booking = $this->bookingRepository->findById($bookingId);

            if (!$booking) {
                throw ValidationException::withMessages([
                    'booking' => ['Permohonan booking tidak ditemukan.'],
                ]);
            }

            if ($booking->status !== 'menunggu') {
                throw ValidationException::withMessages([
                    'booking' => ["Booking ini sudah berstatus '{$booking->status}' dan tidak dapat diproses lagi."],
                ]);
            }

            $updatedBooking = $this->bookingRepository->update($booking, [
                'status' => 'ditolak',
                'rejection_reason' => $reason,
            ]);

            $room = $booking->room;
            $roomName = $room ? "{$room->room_number} ({$room->name})" : "kamar kos";

            // Dispatch notifikasi penolakan santun
            SendWhatsAppNotificationJob::dispatch(
                $booking->phone,
                "Halo {$booking->name}, mohon maaf permohonan booking {$roomName} belum dapat kami setujui saat ini. Alasan: {$reason}. Terima kasih atas minat Anda.",
                'booking_rejected'
            );

            return $updatedBooking;
        });
    }

    /**
     * Generate Temporary Signed URL berlaku 5 menit untuk akses privat KTP.
     */
    public function generateSignedKtpUrl(Booking $booking): string
    {
        return URL::temporarySignedRoute(
            'admin.bookings.ktp.stream',
            now()->addMinutes(5),
            ['id' => $booking->id]
        );
    }
}
