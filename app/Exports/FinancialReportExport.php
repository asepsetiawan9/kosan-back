<?php

declare(strict_types=1);

namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class FinancialReportExport implements FromCollection, WithHeadings, ShouldAutoSize, WithStyles
{
    /**
     * @param Collection<int, array<string, mixed>> $rows
     */
    public function __construct(
        private readonly Collection $rows
    ) {}

    public function collection(): Collection
    {
        return $this->rows->map(function ($row) {
            return [
                'Waktu' => Carbon::parse($row['created_at'])->translatedFormat('d/m/Y H:i'),
                'No. Tagihan' => $row['invoice_number'],
                'Penyewa' => $row['tenant_name'],
                'Kamar' => 'Kamar ' . $row['room_number'],
                'Metode' => $row['method'],
                'Status' => strtoupper((string) $row['status']),
                'Nominal (Rp)' => number_format((float) $row['amount'], 0, ',', '.'),
            ];
        });
    }

    public function headings(): array
    {
        return [
            'Waktu Transaksi',
            'No. Tagihan',
            'Penyewa',
            'Unit Kamar',
            'Metode Pembayaran',
            'Status',
            'Nominal (Rp)',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'color' => ['rgb' => '0F766E']]],
        ];
    }
}
