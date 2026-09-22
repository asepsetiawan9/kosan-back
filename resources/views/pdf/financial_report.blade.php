<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Keuangan & Arus Kas</title>
    <style>
        @page {
            margin: 20mm 15mm 20mm 15mm;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.4;
            color: #1e293b;
        }
        .header {
            border-bottom: 2px solid #0f766e;
            padding-bottom: 10px;
            margin-bottom: 16px;
        }
        .header h1 {
            font-size: 15pt;
            color: #0f766e;
            margin: 0 0 4px 0;
            text-transform: uppercase;
        }
        .header .subtitle {
            font-size: 9.5pt;
            color: #64748b;
        }
        .metrics-table {
            width: 100%;
            margin-bottom: 20px;
            border-collapse: collapse;
        }
        .metric-card {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 10px 12px;
            border-radius: 6px;
            text-align: center;
        }
        .metric-title {
            font-size: 8pt;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .metric-value {
            font-size: 13pt;
            font-weight: bold;
            color: #0f766e;
        }
        .section-title {
            font-size: 11pt;
            font-weight: bold;
            color: #0f766e;
            margin-top: 14px;
            margin-bottom: 8px;
        }
        table.detail-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        table.detail-table th {
            background-color: #f1f5f9;
            color: #334155;
            font-weight: bold;
            text-align: left;
            padding: 6px 8px;
            font-size: 8.5pt;
            border-bottom: 1px solid #cbd5e1;
        }
        table.detail-table td {
            padding: 6px 8px;
            font-size: 8.5pt;
            border-bottom: 1px solid #f1f5f9;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .font-semibold {
            font-weight: 600;
        }
        .footer {
            margin-top: 25px;
            font-size: 7.5pt;
            color: #94a3b8;
            text-align: center;
            border-top: 1px solid #e2e8f0;
            padding-top: 8px;
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>Laporan Keuangan & Arus Kas (Cash-Basis)</h1>
        <div class="subtitle">Periode: <strong>{{ $period_label }}</strong> | Tanggal Cetak: {{ $printed_at }}</div>
    </div>

    <table class="metrics-table">
        <tr>
            <td style="width: 32%; padding-right: 8px;">
                <div class="metric-card">
                    <div class="metric-title">Pemasukan Bersih Kas</div>
                    <div class="metric-value">Rp {{ number_format($total_income, 0, ',', '.') }}</div>
                </div>
            </td>
            <td style="width: 32%; padding: 0 4px;">
                <div class="metric-card">
                    <div class="metric-title">Estimasi Piutang Tertunda</div>
                    <div class="metric-value" style="color: #d97706;">Rp {{ number_format($pending_receivables, 0, ',', '.') }}</div>
                </div>
            </td>
            <td style="width: 32%; padding-left: 8px;">
                <div class="metric-card">
                    <div class="metric-title">Tingkat Okupansi</div>
                    <div class="metric-value" style="color: #4f46e5;">{{ $occupancy_rate }}%</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="section-title">Rincian Pendapatan per Komponen</div>
    <table class="detail-table">
        <thead>
            <tr>
                <th>Komponen Pendapatan</th>
                <th class="text-right">Nominal (Rp)</th>
                <th class="text-right">Porsi (%)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($category_breakdown as $item)
            <tr>
                <td>{{ $item['name'] }}</td>
                <td class="text-right font-semibold">Rp {{ number_format($item['amount'], 0, ',', '.') }}</td>
                <td class="text-right">{{ $item['percentage'] }}%</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="background-color: #f8fafc; font-weight: bold;">
                <td>Total Pemasukan Kas Riil</td>
                <td class="text-right" style="color: #0f766e;">Rp {{ number_format($total_income, 0, ',', '.') }}</td>
                <td class="text-right">100%</td>
            </tr>
        </tfoot>
    </table>

    <div class="section-title">Daftar Transaksi Kas Masuk (Berhasil)</div>
    <table class="detail-table">
        <thead>
            <tr>
                <th style="width: 15%;">Waktu</th>
                <th style="width: 15%;">No. Tagihan</th>
                <th style="width: 25%;">Penyewa / Kamar</th>
                <th style="width: 20%;">Metode Bayar</th>
                <th style="width: 25%;" class="text-right">Nominal</th>
            </tr>
        </thead>
        <tbody>
            @forelse($transactions as $trx)
            <tr>
                <td>{{ \Carbon\Carbon::parse($trx->verified_at ?? $trx->created_at)->translatedFormat('d/m/Y H:i') }}</td>
                <td>{{ $trx->invoice->invoice_number ?? '-' }}</td>
                <td>{{ $trx->invoice->tenancy->tenant_name ?? '-' }} (Kamar {{ $trx->invoice->tenancy->room->room_number ?? '-' }})</td>
                <td>{{ $trx->method === 'gateway' ? 'Gateway (' . strtoupper($trx->gateway_provider ?? 'Auto') . ')' : 'Transfer Manual' }}</td>
                <td class="text-right font-semibold">Rp {{ number_format((float) $trx->amount, 0, ',', '.') }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="5" class="text-center" style="color: #94a3b8; padding: 14px;">Tidak ada transaksi pembayaran pada periode ini.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Dicetak secara otomatis dari Sistem Manajemen Kos & Kontrakan Pro. Dokumen audit internal keuangan resmi.
    </div>

</body>
</html>
