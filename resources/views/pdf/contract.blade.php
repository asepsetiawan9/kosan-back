<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Surat Perjanjian Sewa - {{ $contract->contract_number }}</title>
    <style>
        @page {
            margin: 28mm 20mm 25mm 20mm;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.5;
            color: #1e293b;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #0f766e;
            padding-bottom: 12px;
            margin-bottom: 20px;
        }
        .header h1 {
            font-size: 16pt;
            color: #0f766e;
            margin: 0 0 4px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .header .doc-no {
            font-size: 10pt;
            color: #64748b;
            font-weight: 500;
        }
        .section-title {
            font-size: 11.5pt;
            font-weight: bold;
            color: #0f766e;
            margin-top: 16px;
            margin-bottom: 6px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 3px;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        table.data-table td {
            padding: 4px 6px;
            vertical-align: top;
            font-size: 10.5pt;
        }
        table.data-table td.label {
            width: 32%;
            color: #475569;
        }
        table.data-table td.separator {
            width: 3%;
            text-align: center;
        }
        table.data-table td.value {
            width: 65%;
            font-weight: 600;
            color: #0f172a;
        }
        .clause {
            margin-bottom: 12px;
            text-align: justify;
            font-size: 10pt;
        }
        .clause-title {
            font-weight: bold;
            margin-bottom: 3px;
            color: #334155;
        }
        .signature-table {
            width: 100%;
            margin-top: 30px;
            border-collapse: collapse;
            page-break-inside: avoid;
        }
        .signature-table td {
            width: 50%;
            text-align: center;
            vertical-align: top;
            padding: 10px;
        }
        .signature-box {
            height: 85px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 8px 0;
        }
        .signature-box img {
            max-height: 80px;
            max-width: 180px;
        }
        .watermark-draft {
            color: #cbd5e1;
            border: 2px dashed #94a3b8;
            padding: 12px;
            font-style: italic;
            font-size: 10pt;
            border-radius: 4px;
        }
        .badge-verified {
            display: inline-block;
            font-size: 8pt;
            background-color: #d1fae5;
            color: #065f46;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: bold;
            margin-top: 4px;
        }
        .legal-footer {
            margin-top: 25px;
            font-size: 8pt;
            color: #94a3b8;
            text-align: center;
            border-top: 1px dashed #cbd5e1;
            padding-top: 8px;
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>Surat Perjanjian Sewa Hunian</h1>
        <div class="doc-no">Nomor Dokumen: <strong>{{ $contract->contract_number }}</strong></div>
    </div>

    <p style="margin-bottom: 14px; font-size: 10pt;">
        Pada hari ini, bertempat di properti hunian kos/kontrakan, dibuat dan disepakati perjanjian sewa antara pihak-pihak di bawah ini:
    </p>

    <div class="section-title">I. IDENTITAS PARA PIHAK</div>
    <table class="data-table">
        <tr>
            <td class="label">PIHAK PERTAMA (Pengelola)</td>
            <td class="separator">:</td>
            <td class="value">Manajemen Pengelola Properti Hunian Kos & Kontrakan</td>
        </tr>
        <tr>
            <td class="label">PIHAK KEDUA (Penyewa)</td>
            <td class="separator">:</td>
            <td class="value">{{ $tenancy->tenant_name }}</td>
        </tr>
        <tr>
            <td class="label">Nomor Handphone / WhatsApp</td>
            <td class="separator">:</td>
            <td class="value">{{ $tenancy->tenant_phone }}</td>
        </tr>
        <tr>
            <td class="label">Alamat Email</td>
            <td class="separator">:</td>
            <td class="value">{{ $tenancy->tenant_email ?? '-' }}</td>
        </tr>
    </table>

    <div class="section-title">II. UNIT HUNIAN & KETENTUAN SEWA</div>
    <table class="data-table">
        <tr>
            <td class="label">Nomor / Tipe Kamar</td>
            <td class="separator">:</td>
            <td class="value">Kamar {{ $tenancy->room->room_number }} (Tipe: {{ ucfirst($tenancy->room->type) }})</td>
        </tr>
        <tr>
            <td class="label">Fasilitas Unit</td>
            <td class="separator">:</td>
            <td class="value">
                @if($tenancy->room->facilities && count($tenancy->room->facilities) > 0)
                    {{ $tenancy->room->facilities->pluck('name')->implode(', ') }}
                @else
                    Fasilitas Standar Kamar
                @endif
            </td>
        </tr>
        <tr>
            <td class="label">Masa Periode Sewa</td>
            <td class="separator">:</td>
            <td class="value">{{ \Carbon\Carbon::parse($tenancy->start_date)->translatedFormat('d F Y') }} s/d {{ $tenancy->end_date ? \Carbon\Carbon::parse($tenancy->end_date)->translatedFormat('d F Y') : 'Perpanjangan Berkala' }}</td>
        </tr>
        <tr>
            <td class="label">Biaya Sewa Bulanan</td>
            <td class="separator">:</td>
            <td class="value">Rp {{ number_format((float) $tenancy->room->price, 0, ',', '.') }} / bulan</td>
        </tr>
        <tr>
            <td class="label">Uang Jaminan (Deposit)</td>
            <td class="separator">:</td>
            <td class="value">Rp {{ number_format((float) $tenancy->deposit_amount, 0, ',', '.') }} (Status: {{ ucfirst($tenancy->deposit_status) }})</td>
        </tr>
        <tr>
            <td class="label">Jatuh Tempo Pembayaran</td>
            <td class="separator">:</td>
            <td class="value">Tanggal {{ $tenancy->billing_due_day }} setiap bulannya</td>
        </tr>
    </table>

    <div class="section-title">III. KETENTUAN & KESEPAKATAN HUKUM</div>

    <div class="clause">
        <div class="clause-title">Pasal 1 — Pembayaran & Denda Keterlambatan</div>
        Pihak Kedua berkewajiban membayarkan uang sewa secara tepat waktu paling lambat pada tanggal jatuh tempo yang telah disepakati. Keterlambatan pembayaran lebih dari 3 (tiga) hari kerja dapat dikenakan sanksi peringatan serta denda administrasi sesuai aturan pengelola.
    </div>

    <div class="clause">
        <div class="clause-title">Pasal 2 — Pemeliharaan Fasilitas & Ketertiban</div>
        Pihak Kedua wajib merawat seluruh fasilitas kamar dan fasilitas bersama dengan sebaik-baiknya. Dilarang merusak, mengubah tata ruang permanen tanpa izin tertulis, atau membawa benda berbahaya, senjata tajam, obat-obatan terlarang, serta aktivitas yang melanggar hukum Negara Kesatuan Republik Indonesia.
    </div>

    <div class="clause">
        <div class="clause-title">Pasal 3 — Uang Jaminan (Deposit) & Serah Terima Unit (Checkout)</div>
        Uang deposit jaminan disimpan oleh Pihak Pertama dan akan dikembalikan secara utuh kepada Pihak Kedua setelah masa sewa berakhir, dengan ketentuan seluruh tunggakan biaya sewa/utilitas telah lunas dan kamar diserahkan dalam kondisi bersih serta fasilitas utuh tanpa kerusakan.
    </div>

    <div class="clause">
        <div class="clause-title">Pasal 4 — Keabsahan Tanda Tangan Elektronik</div>
        Kedua belah pihak sepakat bahwa dokumen ini disahkan menggunakan Tanda Tangan Elektronik (TTE) yang memiliki kekuatan hukum mengikat sesuai peraturan perundang-undangan Informasi dan Transaksi Elektronik (UU ITE).
    </div>

    <table class="signature-table">
        <tr>
            <td>
                <strong>PIHAK PERTAMA</strong><br>
                <span>Pengelola Hunian</span>
                <div class="signature-box">
                    <div style="font-weight: bold; color: #0f766e; border: 1.5px solid #0f766e; padding: 10px; border-radius: 6px; font-size: 9pt;">
                        TERVERIFIKASI SISTEM<br>
                        <span style="font-size: 7.5pt; color: #64748b;">Kosan Pro Management</span>
                    </div>
                </div>
                <strong>Manajemen Pengelola</strong>
            </td>
            <td>
                <strong>PIHAK KEDUA</strong><br>
                <span>Penyewa Kamar</span>
                <div class="signature-box">
                    @if(($is_signed ?? false) && !empty($signature_base64))
                        <img src="{{ $signature_base64 }}" alt="Tanda Tangan Digital">
                    @else
                        <div class="watermark-draft">
                            [ DRAF MENUNGGU TTE PENGHUNI ]
                        </div>
                    @endif
                </div>
                <strong>{{ $tenancy->tenant_name }}</strong><br>
                @if($is_signed ?? false)
                    <span class="badge-verified">Ditandatangani secara Sah</span><br>
                    <span style="font-size: 7.5pt; color: #64748b;">Waktu: {{ \Carbon\Carbon::parse($contract->signed_at)->translatedFormat('d M Y H:i:s') }} WIB</span>
                @else
                    <span style="font-size: 8pt; color: #94a3b8;">Belum Ditandatangani</span>
                @endif
            </td>
        </tr>
    </table>

    <div class="legal-footer">
        Dokumen ini diterbitkan secara otomatis dan dienkripsi oleh Sistem Manajemen Hunian Kos & Kontrakan.<br>
        ID Kontrak: {{ $contract->id }} | Hash: {{ sha1($contract->id . $contract->contract_number . ($contract->signed_at ?? 'draft')) }}
    </div>

</body>
</html>
