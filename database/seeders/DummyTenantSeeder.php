<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Complaint;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Room;
use App\Models\Tenancy;
use App\Models\TenantDocument;
use App\Models\User;
use App\Services\TenantExcelImportService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DummyTenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminUser = User::where('role', 'admin')->first();

        // =========================================================================
        // 1. SEED DUMMY TENANTS UNTUK KAMAR SAMPLE (Kamar 101, 102, 201)
        // =========================================================================
        $sampleTenants = [
            [
                'room_number' => '101',
                'name' => 'Budi Santoso',
                'email' => 'budi.santoso@kosan.local',
                'phone' => '6281234567890',
                'nik' => '3201012304950001',
                'start_months_ago' => 6,
                'billing_due_day' => 5,
                'deposit_amount' => 1200000.00,
                'invoice_scenario' => 'lunas',
                'document_scenario' => 'verified',
                'has_complaint' => true,
                'complaint' => [
                    'category' => 'fasilitas_rusak',
                    'description' => 'Lampu LED kamar mandi sempat redup dan berkedip.',
                    'status' => 'selesai',
                    'admin_response' => 'Lampu LED baru berdaya 12 Watt sudah diganti oleh teknisi kos.',
                    'resolved_days_ago' => 2,
                ],
            ],
            [
                'room_number' => '102',
                'name' => 'Siti Rahmawati',
                'email' => 'siti.rahmawati@kosan.local',
                'phone' => '6281298765432',
                'nik' => '3275024508980002',
                'start_months_ago' => 3,
                'billing_due_day' => 10,
                'deposit_amount' => 1750000.00,
                'invoice_scenario' => 'belum_bayar',
                'document_scenario' => 'pending',
                'has_complaint' => true,
                'complaint' => [
                    'category' => 'fasilitas_rusak',
                    'description' => 'Kran wastafel kamar mandi sedikit bocor dan menetes airnya saat ditutup.',
                    'status' => 'diproses',
                    'admin_response' => 'Sedang dijadwalkan teknisi untuk penggantian seal kran hari ini.',
                    'resolved_days_ago' => null,
                ],
            ],
            [
                'room_number' => '201',
                'name' => 'Andi Pratama',
                'email' => 'andi.pratama@kosan.local',
                'phone' => '6281377889900',
                'nik' => '3471011201940003',
                'start_months_ago' => 4,
                'billing_due_day' => 1,
                'deposit_amount' => 2400000.00,
                'invoice_scenario' => 'terlambat',
                'document_scenario' => 'verified',
                'has_complaint' => false,
            ],
        ];

        foreach ($sampleTenants as $data) {
            $room = Room::where('room_number', $data['room_number'])->first();
            if (!$room) {
                continue;
            }

            // 1.1 Buat / Update User Akun Penghuni
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'phone' => $data['phone'],
                    'nik' => $data['nik'],
                    'password' => Hash::make('password123'),
                    'role' => 'penyewa',
                    'must_change_password' => false,
                ]
            );

            // 1.2 Buat / Update Tenancy
            $startDate = Carbon::now()->subMonths($data['start_months_ago'])->startOfMonth()->toDateString();
            $tenancy = Tenancy::updateOrCreate(
                ['room_id' => $room->id, 'status' => 'aktif'],
                [
                    'user_id' => $user->id,
                    'tenant_name' => $data['name'],
                    'tenant_phone' => $data['phone'],
                    'tenant_email' => $data['email'],
                    'start_date' => $startDate,
                    'billing_due_day' => $data['billing_due_day'],
                    'deposit_amount' => $data['deposit_amount'],
                    'deposit_status' => 'ditahan',
                ]
            );

            // Pastikan kamar status terisi
            $room->update(['status' => 'terisi']);

            // 1.3 Dokumen Identitas Penghuni
            if ($data['document_scenario'] === 'verified') {
                TenantDocument::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'document_type' => 'ktp',
                    ],
                    [
                        'file_path' => "private/tenant-documents/{$user->id}/ktp_dummy.jpg",
                        'original_filename' => 'ktp_' . Str::slug($data['name']) . '.jpg',
                        'mime_type' => 'image/jpeg',
                        'file_size' => 450200,
                        'is_verified' => true,
                        'verified_at' => Carbon::now()->subDays(10),
                        'verified_by' => $adminUser?->id,
                        'notes' => 'KTP diverifikasi valid sesuai data NIK dukcapil.',
                    ]
                );
            } elseif ($data['document_scenario'] === 'pending') {
                TenantDocument::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'document_type' => 'ktp',
                    ],
                    [
                        'file_path' => "private/tenant-documents/{$user->id}/ktp_dummy.jpg",
                        'original_filename' => 'ktp_' . Str::slug($data['name']) . '.jpg',
                        'mime_type' => 'image/jpeg',
                        'file_size' => 512000,
                        'is_verified' => false,
                        'verified_at' => null,
                        'verified_by' => null,
                        'notes' => 'Menunggu verifikasi admin.',
                    ]
                );
            }

            // 1.4 Tiket Aduan / Complaint
            if (!empty($data['has_complaint']) && isset($data['complaint'])) {
                $comp = $data['complaint'];
                Complaint::updateOrCreate(
                    [
                        'tenancy_id' => $tenancy->id,
                        'description' => $comp['description'],
                    ],
                    [
                        'category' => $comp['category'],
                        'status' => $comp['status'],
                        'admin_response' => $comp['admin_response'],
                        'resolved_at' => $comp['resolved_days_ago'] !== null
                            ? Carbon::now()->subDays($comp['resolved_days_ago'])
                            : null,
                    ]
                );
            }

            // 1.5 Skenario Invoices & Tagihan
            $currentPeriod = Carbon::now()->format('Y-m');
            $monthlyRent = (float) $room->base_price;

            if ($data['invoice_scenario'] === 'lunas') {
                // Invoice Bulan Ini Lunas
                $invNumber = 'INV/' . Carbon::now()->format('Ym') . "/{$data['room_number']}/" . strtoupper(Str::random(4));
                $invoice = Invoice::updateOrCreate(
                    [
                        'tenancy_id' => $tenancy->id,
                        'period' => $currentPeriod,
                    ],
                    [
                        'invoice_number' => $invNumber,
                        'total_amount' => $monthlyRent,
                        'paid_amount' => $monthlyRent,
                        'status' => 'lunas',
                        'due_date' => Carbon::now()->startOfMonth()->addDays($data['billing_due_day'] - 1)->toDateString(),
                    ]
                );

                InvoiceItem::updateOrCreate(
                    ['invoice_id' => $invoice->id, 'item_type' => 'sewa'],
                    [
                        'description' => "Biaya Sewa Kamar {$data['room_number']} Periode " . Carbon::now()->translatedFormat('F Y'),
                        'amount' => $monthlyRent,
                    ]
                );

                Payment::updateOrCreate(
                    ['invoice_id' => $invoice->id],
                    [
                        'amount' => $monthlyRent,
                        'method' => 'gateway',
                        'source' => 'web',
                        'gateway_provider' => 'midtrans',
                        'gateway_transaction_id' => 'MID-' . strtoupper(Str::random(10)),
                        'status' => 'success',
                        'verified_at' => Carbon::now()->subDays(2),
                        'notes' => 'Pembayaran lunas via Midtrans Snap.',
                    ]
                );
            } elseif ($data['invoice_scenario'] === 'belum_bayar') {
                // Invoice Bulan Ini Belum Bayar
                $dueDate = Carbon::now()->startOfMonth()->addDays($data['billing_due_day'] - 1);
                $invNumber = 'INV/' . Carbon::now()->format('Ym') . "/{$data['room_number']}/" . strtoupper(Str::random(4));

                $invoice = Invoice::updateOrCreate(
                    [
                        'tenancy_id' => $tenancy->id,
                        'period' => $currentPeriod,
                    ],
                    [
                        'invoice_number' => $invNumber,
                        'total_amount' => $monthlyRent,
                        'paid_amount' => 0,
                        'status' => 'belum_bayar',
                        'due_date' => $dueDate->toDateString(),
                    ]
                );

                InvoiceItem::updateOrCreate(
                    ['invoice_id' => $invoice->id, 'item_type' => 'sewa'],
                    [
                        'description' => "Biaya Sewa Kamar {$data['room_number']} Periode " . Carbon::now()->translatedFormat('F Y'),
                        'amount' => $monthlyRent,
                    ]
                );
            } elseif ($data['invoice_scenario'] === 'terlambat') {
                // Invoice Bulan Lalu Terlambat / Menunggak
                $prevMonth = Carbon::now()->subMonth();
                $prevPeriod = $prevMonth->format('Y-m');
                $prevDueDate = $prevMonth->copy()->startOfMonth()->addDays($data['billing_due_day'] - 1);
                $invNumber = 'INV/' . $prevMonth->format('Ym') . "/{$data['room_number']}/" . strtoupper(Str::random(4));

                $invoice = Invoice::updateOrCreate(
                    [
                        'tenancy_id' => $tenancy->id,
                        'period' => $prevPeriod,
                    ],
                    [
                        'invoice_number' => $invNumber,
                        'total_amount' => $monthlyRent,
                        'paid_amount' => 0,
                        'status' => 'terlambat',
                        'due_date' => $prevDueDate->toDateString(),
                    ]
                );

                InvoiceItem::updateOrCreate(
                    ['invoice_id' => $invoice->id, 'item_type' => 'sewa'],
                    [
                        'description' => "Biaya Sewa Kamar {$data['room_number']} Periode " . $prevMonth->translatedFormat('F Y'),
                        'amount' => $monthlyRent,
                    ]
                );

                // Tambahkan juga invoice bulan ini (belum bayar)
                $curDueDate = Carbon::now()->startOfMonth()->addDays($data['billing_due_day'] - 1);
                $invCurNumber = 'INV/' . Carbon::now()->format('Ym') . "/{$data['room_number']}/" . strtoupper(Str::random(4));

                $curInvoice = Invoice::updateOrCreate(
                    [
                        'tenancy_id' => $tenancy->id,
                        'period' => $currentPeriod,
                    ],
                    [
                        'invoice_number' => $invCurNumber,
                        'total_amount' => $monthlyRent,
                        'paid_amount' => 0,
                        'status' => 'belum_bayar',
                        'due_date' => $curDueDate->toDateString(),
                    ]
                );

                InvoiceItem::updateOrCreate(
                    ['invoice_id' => $curInvoice->id, 'item_type' => 'sewa'],
                    [
                        'description' => "Biaya Sewa Kamar {$data['room_number']} Periode " . Carbon::now()->translatedFormat('F Y'),
                        'amount' => $monthlyRent,
                    ]
                );
            }
        }

        // =========================================================================
        // 2. IMPORT DATA PENGHUNI DARI FILE EXCEL JIKA BERKAS TERSEDIA
        // =========================================================================
        $excelCandidates = [
            base_path('../data penguni ksoan.xlsx'),
            base_path('../data_penghuni_kos_ai_ready.xlsx'),
            base_path('data penguni ksoan.xlsx'),
            base_path('data_penghuni_kos_ai_ready.xlsx'),
        ];

        $targetExcel = null;
        foreach ($excelCandidates as $cand) {
            if (file_exists($cand)) {
                $targetExcel = $cand;
                break;
            }
        }

        if ($targetExcel) {
            /** @var TenantExcelImportService $importService */
            $importService = app(TenantExcelImportService::class);
            $parsed = $importService->parseFile($targetExcel);
            if (!empty($parsed['rows'])) {
                $importService->executeImport($parsed['rows']);
            }
        }
    }
}
