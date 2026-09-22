<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Room;
use App\Models\Tenancy;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use App\Repositories\Contracts\RoomRepositoryInterface;
use App\Repositories\Contracts\TenancyRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TenancyService
{
    public function __construct(
        private readonly TenancyRepositoryInterface $tenancyRepository,
        private readonly RoomRepositoryInterface $roomRepository,
        private readonly InvoiceRepositoryInterface $invoiceRepository
    ) {}

    public function getPaginatedTenancies(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->tenancyRepository->getPaginated($filters, $perPage);
    }

    public function getTenancyById(string $id): Tenancy
    {
        $tenancy = $this->tenancyRepository->findById($id);
        if (!$tenancy) {
            throw ValidationException::withMessages(['tenancy' => ['Data penyewa tidak ditemukan.']]);
        }
        return $tenancy;
    }

    public function createTenancy(array $data, bool $createFirstInvoice = true): Tenancy
    {
        return DB::transaction(function () use ($data, $createFirstInvoice) {
            $room = $this->roomRepository->findById($data['room_id']);
            if (!$room) {
                throw ValidationException::withMessages(['room_id' => ['Kamar tidak ditemukan.']]);
            }

            if ($room->status !== 'kosong') {
                throw ValidationException::withMessages([
                    'room_id' => ["Kamar nomor {$room->room_number} sedang tidak berstatus kosong (status: {$room->status})."],
                ]);
            }

            // Simpan record tenancy
            $data['status'] = 'aktif';
            $data['deposit_status'] = 'ditahan';
            $tenancy = $this->tenancyRepository->create($data);

            // Update kamar menjadi terisi
            $this->roomRepository->update($room, ['status' => 'terisi']);

            // Buat invoice pertama otomatis jika dicentang
            if ($createFirstInvoice) {
                $startDate = Carbon::parse($data['start_date']);
                $period = $startDate->format('Y-m');
                $invoiceNumber = $this->invoiceRepository->generateInvoiceNumber($period);

                $rentAmount = (float) $room->base_price;
                $depositAmount = isset($data['deposit_amount']) ? (float) $data['deposit_amount'] : 0.0;
                $totalAmount = $rentAmount + $depositAmount;

                $items = [
                    [
                        'description' => "Biaya Sewa Kamar {$room->room_number} Periode " . $startDate->translatedFormat('F Y'),
                        'amount' => $rentAmount,
                        'item_type' => 'sewa',
                    ],
                ];

                if ($depositAmount > 0) {
                    $items[] = [
                        'description' => "Uang Jaminan / Deposit Awal Sewa Kamar {$room->room_number}",
                        'amount' => $depositAmount,
                        'item_type' => 'deposit',
                    ];
                }

                $dueDate = $startDate->copy()->addDays(3)->toDateString();

                $this->invoiceRepository->create([
                    'tenancy_id' => $tenancy->id,
                    'invoice_number' => $invoiceNumber,
                    'period' => $period,
                    'total_amount' => $totalAmount,
                    'paid_amount' => 0,
                    'status' => 'belum_bayar',
                    'due_date' => $dueDate,
                ], $items);
            }

            return $tenancy->fresh(['room', 'invoices.items']);
        });
    }

    public function checkout(string $id, array $checkoutData): Tenancy
    {
        $tenancy = $this->getTenancyById($id);

        if ($tenancy->status !== 'aktif') {
            throw ValidationException::withMessages([
                'tenancy' => ['Hanya sewa dengan status aktif yang dapat dilakukan proses checkout.'],
            ]);
        }

        return DB::transaction(function () use ($tenancy, $checkoutData) {
            $depositDeduction = isset($checkoutData['deposit_deduction']) ? (float) $checkoutData['deposit_deduction'] : 0.0;
            $depositAmount = (float) $tenancy->deposit_amount;

            $depositStatus = 'dikembalikan';
            if ($depositDeduction > 0) {
                $depositStatus = ($depositDeduction >= $depositAmount) ? 'dipotong' : 'dipotong';
            }

            $updateData = [
                'status' => 'selesai',
                'checkout_date' => $checkoutData['checkout_date'] ?? Carbon::now()->toDateString(),
                'deposit_deduction' => $depositDeduction,
                'deduction_reason' => $checkoutData['deduction_reason'] ?? null,
                'deposit_status' => $depositStatus,
            ];

            $updatedTenancy = $this->tenancyRepository->update($tenancy, $updateData);

            // Kembalikan status kamar ke kosong (atau status tujuan seperti maintenance jika ditentukan)
            $nextRoomStatus = $checkoutData['next_room_status'] ?? 'kosong';
            $room = $this->roomRepository->findById($tenancy->room_id);
            if ($room) {
                $this->roomRepository->update($room, ['status' => $nextRoomStatus]);
            }

            return $updatedTenancy->fresh(['room', 'invoices']);
        });
    }
}
