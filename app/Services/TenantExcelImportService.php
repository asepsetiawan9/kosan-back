<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenancy;
use App\Models\User;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class TenantExcelImportService
{
    /**
     * Indonesian month names mapping.
     */
    protected const MONTH_MAP = [
        'jan' => 1, 'januari' => 1,
        'feb' => 2, 'februari' => 2,
        'mar' => 3, 'maret' => 3,
        'apr' => 4, 'april' => 4,
        'mei' => 5, 'may' => 5,
        'jun' => 6, 'juni' => 6,
        'jul' => 7, 'juli' => 7,
        'agu' => 8, 'agust' => 8, 'agustus' => 8, 'aug' => 8,
        'sep' => 9, 'sept' => 9, 'september' => 9,
        'okt' => 10, 'oktober' => 10, 'oct' => 10,
        'nov' => 11, 'november' => 11,
        'des' => 12, 'desember' => 12, 'dec' => 12,
    ];

    /**
     * Parse and inspect the entire Excel file.
     *
     * @return array{rows: array<int, array>, summary: array<string, int>, errors: array<int, string>}
     */
    public function parseFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Berkas tidak ditemukan: {$filePath}");
        }

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rawRows = $sheet->toArray(null, true, true, true);

        if (empty($rawRows)) {
            return [
                'rows' => [],
                'summary' => ['total' => 0, 'valid' => 0, 'empty_rooms' => 0, 'unpaid' => 0, 'warnings' => 0],
                'errors' => ['File Excel kosong.'],
            ];
        }

        // 1. Locate header row
        $headerRowIndex = null;
        $columnMapping = [];

        foreach ($rawRows as $rowIndex => $row) {
            $mapping = $this->detectHeaderMapping($row);
            if (!empty($mapping['no_pintu'])) {
                $headerRowIndex = $rowIndex;
                $columnMapping = $mapping;
                break;
            }
        }

        if ($headerRowIndex === null) {
            throw new \RuntimeException('Gagal mendeteksi baris header (kolom No Pintu / no_pintu tidak ditemukan).');
        }

        // 2. Process data rows
        $parsedRows = [];
        $seenRoomNumbers = [];
        $totalValid = 0;
        $totalEmptyRooms = 0;
        $totalUnpaid = 0;
        $totalWarnings = 0;

        foreach ($rawRows as $rowIndex => $row) {
            if ($rowIndex <= $headerRowIndex) {
                continue;
            }

            // Skip rows where all cells are empty
            $nonEmptyCells = array_filter($row, fn($val) => !is_null($val) && trim((string) $val) !== '');
            if (empty($nonEmptyCells)) {
                continue;
            }

            $parsed = $this->parseRow($row, $columnMapping, $rowIndex);

            if ($parsed['room_number'] === null) {
                // Not a valid door row
                continue;
            }

            // Check duplicate room number in file
            if (isset($seenRoomNumbers[$parsed['room_number']])) {
                $parsed['warnings'][] = "Duplikasi nomor pintu '{$parsed['room_number']}' (pertama terlihat di baris {$seenRoomNumbers[$parsed['room_number']]})";
            } else {
                $seenRoomNumbers[$parsed['room_number']] = $rowIndex;
            }

            if (!empty($parsed['warnings'])) {
                $totalWarnings += count($parsed['warnings']);
            }

            if ($parsed['status_kamar'] === 'kosong') {
                $totalEmptyRooms++;
            } else {
                $totalValid++;
            }

            if ($parsed['is_unpaid']) {
                $totalUnpaid++;
            }

            $parsedRows[] = $parsed;
        }

        return [
            'rows' => $parsedRows,
            'summary' => [
                'total' => count($parsedRows),
                'valid' => $totalValid,
                'empty_rooms' => $totalEmptyRooms,
                'unpaid' => $totalUnpaid,
                'warnings' => $totalWarnings,
            ],
            'errors' => [],
        ];
    }

    /**
     * Execute actual database import.
     *
     * @param array<int, array> $parsedRows
     * @param array<string, mixed> $options
     * @return array<string, int>
     */
    public function executeImport(array $parsedRows, array $options = []): array
    {
        return DB::transaction(function () use ($parsedRows, $options) {
            $createdProperties = 0;
            $createdRooms = 0;
            $updatedRooms = 0;
            $createdTenancies = 0;
            $createdUsers = 0;
            $createdInvoices = 0;

            $adminUser = User::where('role', 'admin')->first();

            // Cache properties by owner name or create
            $propertyCache = [];

            foreach ($parsedRows as $item) {
                $ownerName = $item['pemilik'] ?: 'Pemilik Utama';
                $location = $item['lokasi'] ?: 'Belakang Rumah';

                if (!isset($propertyCache[$ownerName])) {
                    $existingProperty = Property::where('owner_name', $ownerName)->first();
                    if (!$existingProperty) {
                        $propName = 'Kos ' . Str::title($ownerName);
                        if (!empty($location) && $location !== 'Belakang Rumah') {
                            $propName .= " ({$location})";
                        }

                        $existingProperty = Property::create([
                            'name' => $propName,
                            'address' => $location,
                            'city' => 'Bekasi',
                            'province' => 'Jawa Barat',
                            'owner_name' => $ownerName,
                            'managed_by' => $adminUser?->id,
                        ]);
                        $createdProperties++;
                    }
                    $propertyCache[$ownerName] = $existingProperty;
                }

                $property = $propertyCache[$ownerName];

                // 1. Room
                $room = Room::where('room_number', $item['room_number'])->first();
                $isNewRoom = false;

                if (!$room) {
                    $room = new Room();
                    $room->room_number = $item['room_number'];
                    $isNewRoom = true;
                }

                $room->property_id = $property->id;
                $room->name = "Kamar {$item['room_number']}";
                $room->type = 'standard';
                $room->base_price = $item['base_price'] ?: 500000;
                $room->description = "Lokasi: {$location}" . ($item['keterangan'] ? " | Keterangan: {$item['keterangan']}" : '');
                $room->status = $item['status_kamar'];
                $room->save();

                if ($isNewRoom) {
                    $createdRooms++;
                } else {
                    $updatedRooms++;
                }

                // 2. Tenant and Tenancy (if not empty room)
                if ($item['status_kamar'] === 'terisi' && !empty($item['nama_kontak'])) {
                    $phone = $item['nomor_telepon'] ?: null;
                    $user = null;

                    if ($phone) {
                        $user = User::where('phone', $phone)->first();
                    }

                    if (!$user) {
                        $email = strtolower($item['room_number']) . '.tenant@kosan.local';
                        $user = User::where('email', $email)->first();
                    }

                    if (!$user) {
                        $user = User::create([
                            'name' => $item['nama_kontak'],
                            'phone' => $phone ?: '62800' . rand(1000000, 9999999),
                            'email' => strtolower($item['room_number']) . '.tenant@kosan.local',
                            'password' => Hash::make('password123'),
                            'role' => 'penyewa',
                            'must_change_password' => false,
                        ]);
                        $createdUsers++;
                    }

                    // Existing or new Tenancy
                    $tenancy = Tenancy::where('room_id', $room->id)
                        ->where('status', 'aktif')
                        ->first();

                    if (!$tenancy) {
                        $startDate = $item['tanggal_masuk'] ?: now()->startOfMonth()->toDateString();
                        $tenancy = Tenancy::create([
                            'room_id' => $room->id,
                            'user_id' => $user->id,
                            'tenant_name' => $item['nama_kontak'],
                            'tenant_phone' => $user->phone,
                            'tenant_email' => $user->email,
                            'start_date' => $startDate,
                            'billing_due_day' => $item['tempo'] ?: 1,
                            'deposit_amount' => 0,
                            'status' => 'aktif',
                        ]);
                        $createdTenancies++;
                    }

                    // 3. Invoice if Belum Bayar (BB)
                    if ($item['is_unpaid'] && $item['tagihan_amount'] > 0) {
                        $period = '2026-08';
                        $existingInvoice = Invoice::where('tenancy_id', $tenancy->id)
                            ->where('period', $period)
                            ->first();

                        if (!$existingInvoice) {
                            $dueDate = Carbon::create(2026, 8, min((int) ($item['tempo'] ?: 1), 28))->toDateString();
                            $invNum = 'INV/202608/' . $item['room_number'] . '/' . strtoupper(Str::random(4));

                            $invoice = Invoice::create([
                                'tenancy_id' => $tenancy->id,
                                'invoice_number' => $invNum,
                                'period' => $period,
                                'total_amount' => $item['tagihan_amount'],
                                'paid_amount' => 0,
                                'status' => 'belum_bayar',
                                'due_date' => $dueDate,
                            ]);

                            InvoiceItem::create([
                                'invoice_id' => $invoice->id,
                                'description' => "Tagihan Sewa Kamar {$item['room_number']} Periode Agustus 2026",
                                'amount' => $item['tagihan_amount'],
                                'item_type' => 'sewa',
                            ]);

                            $createdInvoices++;
                        }
                    }
                }
            }

            return [
                'properties_created' => $createdProperties,
                'rooms_created' => $createdRooms,
                'rooms_updated' => $updatedRooms,
                'users_created' => $createdUsers,
                'tenancies_created' => $createdTenancies,
                'invoices_created' => $createdInvoices,
            ];
        });
    }

    /**
     * Detect column mapping from header row.
     *
     * @param array<string, mixed> $headerRow
     * @return array<string, string>
     */
    protected function detectHeaderMapping(array $headerRow): array
    {
        $mapping = [];

        foreach ($headerRow as $colLetter => $rawTitle) {
            if ($rawTitle === null) {
                continue;
            }

            $clean = strtolower(trim((string) $rawTitle));
            $clean = str_replace(['_', '-', '/', '\\'], ' ', $clean);
            $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;

            if (preg_match('/^(no pintu|nomor pintu|pintu)$/i', $clean)) {
                $mapping['no_pintu'] = $colLetter;
            } elseif (preg_match('/^(tanggal masuk|tgl masuk)$/i', $clean)) {
                $mapping['tanggal_masuk'] = $colLetter;
            } elseif (preg_match('/^(pemilik|owner)$/i', $clean)) {
                $mapping['pemilik'] = $colLetter;
            } elseif (preg_match('/^(nominal|sewa|harga)$/i', $clean)) {
                $mapping['nominal'] = $colLetter;
            } elseif (preg_match('/^(tagihan|sisa tagihan)$/i', $clean)) {
                $mapping['tagihan'] = $colLetter;
            } elseif (preg_match('/^(keterangan|ket)$/i', $clean)) {
                $mapping['keterangan'] = $colLetter;
            } elseif (preg_match('/^(terakhir bayar|tgl bayar)$/i', $clean)) {
                $mapping['terakhir_bayar'] = $colLetter;
            } elseif (preg_match('/^(tempo|jatuh tempo|due)$/i', $clean)) {
                $mapping['tempo'] = $colLetter;
            } elseif (preg_match('/^(nama dan nomor telpon|nama kontak|nama penghuni|nama)$/i', $clean)) {
                $mapping['nama_kontak'] = $colLetter;
            } elseif (preg_match('/^(no telpon|nomor telepon|telepon|hp|wa)$/i', $clean)) {
                $mapping['nomor_telepon'] = $colLetter;
            } elseif (preg_match('/^(lokasi|alamat)$/i', $clean)) {
                $mapping['lokasi'] = $colLetter;
            } elseif (preg_match('/^(aksi|janji bayar|aksi janji bayar)$/i', $clean)) {
                $mapping['janji_bayar'] = $colLetter;
            }
        }

        return $mapping;
    }

    /**
     * Parse a single row.
     *
     * @param array<string, mixed> $row
     * @param array<string, string> $mapping
     * @param int $rowIndex
     * @return array<string, mixed>
     */
    protected function parseRow(array $row, array $mapping, int $rowIndex): array
    {
        $warnings = [];

        // 1. No Pintu
        $rawDoor = $this->getVal($row, $mapping, 'no_pintu');
        $roomNumber = null;
        if (!is_null($rawDoor) && trim((string) $rawDoor) !== '') {
            $numOnly = preg_replace('/[^\d]/', '', (string) $rawDoor);
            if ($numOnly !== '') {
                $roomNumber = 'P' . str_pad($numOnly, 2, '0', STR_PAD_LEFT);
            } else {
                $roomNumber = strtoupper(trim((string) $rawDoor));
            }
        }

        // 2. Pemilik
        $pemilik = trim((string) $this->getVal($row, $mapping, 'pemilik'));

        // 3. Nama & Telepon
        $rawNama = $this->getVal($row, $mapping, 'nama_kontak');
        $rawTelepon = $this->getVal($row, $mapping, 'nomor_telepon');

        $namaKontak = '';
        $nomorTelepon = '';

        if (!is_null($rawNama) && trim((string) $rawNama) !== '') {
            $rawNamaStr = trim((string) $rawNama);
            if (str_contains($rawNamaStr, '/')) {
                // e.g. "ENDANG DARMA / 0812-3415-5739"
                $parts = explode('/', $rawNamaStr, 2);
                $namaKontak = trim($parts[0]);
                $phoneInName = trim($parts[1]);
                if (empty($rawTelepon)) {
                    $rawTelepon = $phoneInName;
                }
            } else {
                $namaKontak = $rawNamaStr;
            }
        }

        if (!is_null($rawTelepon) && trim((string) $rawTelepon) !== '') {
            $cleanPhone = PhoneNumber::normalize((string) $rawTelepon);
            if (!PhoneNumber::isValid($cleanPhone)) {
                $warnings[] = "Nomor telepon '{$rawTelepon}' tidak standar format Indonesia.";
            }
            $nomorTelepon = $cleanPhone;
        }

        // 4. Nominal & Tagihan
        $rawNominal = trim((string) $this->getVal($row, $mapping, 'nominal'));
        $rawTagihan = trim((string) $this->getVal($row, $mapping, 'tagihan'));

        $isEmptyRoom = false;
        $isUnpaid = false;
        $basePrice = 0.0;
        $tagihanAmount = 0.0;

        $upperNominal = strtoupper($rawNominal);

        if ($upperNominal === 'KS') {
            $isEmptyRoom = true;
        } elseif ($upperNominal === 'BB') {
            $isUnpaid = true;
            $parsedTagihan = $this->parseMoney($rawTagihan);
            if ($parsedTagihan > 0) {
                $tagihanAmount = $parsedTagihan;
                $basePrice = $parsedTagihan;
            }
        } else {
            $parsedNominal = $this->parseMoney($rawNominal);
            if ($parsedNominal > 0) {
                $basePrice = $parsedNominal;
            }
        }

        if (!empty($rawTagihan) && $tagihanAmount === 0.0) {
            $tagihanAmount = $this->parseMoney($rawTagihan);
        }

        $statusKamar = $isEmptyRoom || (empty($namaKontak) && $basePrice === 0.0) ? 'kosong' : 'terisi';

        // 5. Tanggal Masuk
        $rawTanggalMasuk = $this->getVal($row, $mapping, 'tanggal_masuk');
        $parsedTanggalMasuk = $this->parseDate($rawTanggalMasuk);
        if ($rawTanggalMasuk && !$parsedTanggalMasuk) {
            $warnings[] = "Format tanggal masuk '{$rawTanggalMasuk}' belum teridentifikasi.";
        }

        // 6. Tempo
        $rawTempo = $this->getVal($row, $mapping, 'tempo');
        $tempo = null;
        if (!is_null($rawTempo) && trim((string) $rawTempo) !== '') {
            $numTempo = (int) preg_replace('/[^\d]/', '', (string) $rawTempo);
            if ($numTempo >= 1 && $numTempo <= 31) {
                $tempo = $numTempo;
            } else {
                $warnings[] = "Nilai tempo '{$rawTempo}' di luar rentang hari 1-31.";
            }
        }

        // 7. Terakhir Bayar
        $rawTerakhirBayar = trim((string) $this->getVal($row, $mapping, 'terakhir_bayar'));

        // 8. Lokasi, Keterangan, Janji Bayar
        $lokasi = trim((string) $this->getVal($row, $mapping, 'lokasi'));
        $keterangan = trim((string) $this->getVal($row, $mapping, 'keterangan'));
        $janjiBayar = trim((string) $this->getVal($row, $mapping, 'janji_bayar'));

        return [
            'row_index' => $rowIndex,
            'raw_room_number' => (string) $rawDoor,
            'room_number' => $roomNumber,
            'pemilik' => $pemilik,
            'nama_kontak' => $namaKontak,
            'nomor_telepon' => $nomorTelepon,
            'status_kamar' => $statusKamar,
            'is_empty_room' => $isEmptyRoom,
            'is_unpaid' => $isUnpaid,
            'nominal_raw' => $rawNominal,
            'base_price' => $basePrice,
            'tagihan_raw' => $rawTagihan,
            'tagihan_amount' => $tagihanAmount,
            'tanggal_masuk_raw' => (string) $rawTanggalMasuk,
            'tanggal_masuk' => $parsedTanggalMasuk,
            'tempo_raw' => (string) $rawTempo,
            'tempo' => $tempo,
            'terakhir_bayar_raw' => $rawTerakhirBayar,
            'lokasi' => $lokasi,
            'keterangan' => $keterangan,
            'janji_bayar' => $janjiBayar,
            'warnings' => $warnings,
        ];
    }

    /**
     * Parse Indonesian currency string or number to float.
     */
    protected function parseMoney(?string $str): float
    {
        if (empty($str)) {
            return 0.0;
        }

        $clean = preg_replace('/[^\d]/', '', $str) ?? '';
        return (float) $clean;
    }

    /**
     * Parse multi-format dates including Indonesian textual months.
     */
    protected function parseDate(mixed $raw): ?string
    {
        if (empty($raw)) {
            return null;
        }

        // 1. If PhpSpreadsheet or numeric Excel date
        if (is_numeric($raw) && (float) $raw > 25000 && (float) $raw < 60000) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $raw))->toDateString();
            } catch (\Throwable) {
                // fallback to string
            }
        }

        $str = trim((string) $raw);

        // Standard ISO (YYYY-MM-DD or YYYY-MM-DD HH:mm:ss)
        if (preg_match('#^(\d{4})[-/](\d{1,2})[-/](\d{1,2})#', $str, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        // Format DD-MMM-YY (e.g. 11-Feb-26, 18-Feb-26, 3-Sep-25)
        if (preg_match('#^(\d{1,2})[-/\s]+([a-zA-Z]+)[-/\s]+(\d{2,4})$#i', $str, $m)) {
            $day = (int) $m[1];
            $monthStr = strtolower($m[2]);
            $year = (int) $m[3];

            if ($year < 100) {
                $year += 2000;
            }

            $month = self::MONTH_MAP[$monthStr] ?? null;
            if ($month) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        // Format D MMMM YYYY (e.g. 19 Agu 2023, 7 Mei 2024, 28 Des 2024)
        if (preg_match('#^(\d{1,2})\s+([a-zA-Z]+)\s+(\d{4})$#i', $str, $m)) {
            $day = (int) $m[1];
            $monthStr = strtolower($m[2]);
            $year = (int) $m[3];

            $month = self::MONTH_MAP[$monthStr] ?? null;
            if ($month) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        return null;
    }

    /**
     * Get value safely from row by mapped column letter.
     */
    protected function getVal(array $row, array $mapping, string $key): mixed
    {
        if (!isset($mapping[$key])) {
            return null;
        }

        $colLetter = $mapping[$key];
        return $row[$colLetter] ?? null;
    }
}
