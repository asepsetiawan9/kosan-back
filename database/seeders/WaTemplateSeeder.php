<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\WaTemplate;
use Illuminate\Database\Seeder;

class WaTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'key' => 'reminder_h_minus_3',
                'title' => 'Pengingat Tagihan H-3',
                'body' => "Halo {{nama}}, pengingat dari {{nama_kos}}.\nTagihan kamar {{kamar}} periode {{periode}} sebesar {{nominal}} jatuh tempo pada {{jatuh_tempo}} ({{sisa_hari}} hari lagi).\nTransfer ke: {{no_rekening}}\nSetelah transfer, kirim foto bukti transfer ke nomor ini. Terima kasih 🙏",
            ],
            [
                'key' => 'reminder_h_minus_1',
                'title' => 'Pengingat Tagihan H-1',
                'body' => "Halo {{nama}}, pengingat dari {{nama_kos}}.\nBesok adalah batas jatuh tempo tagihan sewa kamar {{kamar}} periode {{periode}} sebesar {{nominal}} ({{jatuh_tempo}}).\nTransfer ke: {{no_rekening}}\nSetelah transfer, kirim foto bukti transfer ke nomor ini. Terima kasih 🙏",
            ],
            [
                'key' => 'reminder_due_today',
                'title' => 'Pengingat Tagihan Hari Jatuh Tempo',
                'body' => "Halo {{nama}}, hari ini jatuh tempo tagihan kamar {{kamar}} periode {{periode}} sebesar {{nominal}}.\nTransfer ke: {{no_rekening}}\nKirim foto bukti transfer ke nomor ini setelah membayar. Terima kasih 🙏",
            ],
            [
                'key' => 'reminder_overdue',
                'title' => 'Pengingat Tagihan Terlewat Jatuh Tempo',
                'body' => "Halo {{nama}}, tagihan kamar {{kamar}} periode {{periode}} sebesar {{nominal}} sudah melewati jatuh tempo ({{jatuh_tempo}}).\nMohon segera dilunasi dan kirim foto bukti transfer ke nomor ini. Jika sudah membayar, abaikan pesan ini.",
            ],
            [
                'key' => 'reminder_h_plus_5',
                'title' => 'Pengingat Keterlambatan H+5',
                'body' => "Halo {{nama}}, tagihan kamar {{kamar}} periode {{periode}} sebesar {{nominal}} telah melewati jatuh tempo 5 hari.\nMohon kerjasamanya untuk segera melakukan pelunasan ke: {{no_rekening}}.\nKirim bukti transfer ke nomor ini. Terima kasih 🙏",
            ],
            [
                'key' => 'reminder_h_plus_10',
                'title' => 'Pengingat Keterlambatan H+10',
                'body' => "Halo {{nama}}, tagihan kamar {{kamar}} periode {{periode}} sebesar {{nominal}} belum terlunasi (terlambat 10 hari).\nMohon segera melunasi kewajiban sewa kos Anda ke: {{no_rekening}} demi kenyamanan bersama.",
            ],
            [
                'key' => 'reminder_h_plus_15',
                'title' => 'Peringatan Keterlambatan H+15',
                'body' => "PERINGATAN: Halo {{nama}}, tagihan sewa kamar {{kamar}} telah terlambat 15 hari sebesar {{nominal}}.\nHarap segera hubungi pengelola {{nama_kos}} dan selesaikan pelunasan hari ini.",
            ],
            [
                'key' => 'proof_received',
                'title' => 'Konfirmasi Bukti Transfer Diterima',
                'body' => "Terima kasih {{nama}}, bukti transfer untuk periode {{periode}} sudah kami terima dan sedang diverifikasi oleh admin. Kami akan mengabari kembali setelah verifikasi selesai.",
            ],
            [
                'key' => 'proof_approved',
                'title' => 'Bukti Transfer Disetujui (Lunas)',
                'body' => "Halo {{nama}}, pembayaran kamar {{kamar}} periode {{periode}} sebesar {{nominal}} sudah kami terima dan dinyatakan LUNAS. Terima kasih 🙏",
            ],
            [
                'key' => 'proof_rejected',
                'title' => 'Bukti Transfer Ditolak',
                'body' => "Halo {{nama}}, mohon maaf, bukti transfer untuk periode {{periode}} belum dapat kami terima.\nAlasan: {{alasan_penolakan}}\nSilakan kirim ulang bukti transfer yang benar ke nomor ini.",
            ],
            [
                'key' => 'bot_help',
                'title' => 'Menu Bantuan Bot',
                'body' => "Halo, ini layanan otomatis {{nama_kos}}.\n• Kirim *foto bukti transfer* untuk melaporkan pembayaran sewa\n• Ketik *tagihan* untuk melihat tagihan aktif Anda\n• Ketik *aduan* untuk status keluhan & perbaikan fasilitas\n• Ketik *stop* untuk berhenti menerima pengingat, *mulai* untuk mengaktifkan kembali",
            ],
            [
                'key' => 'bot_unknown_number',
                'title' => 'Balasan Nomor Belum Terdaftar',
                'body' => "Maaf, nomor ini belum terdaftar sebagai penghuni di {{nama_kos}}. Silakan hubungi pengelola kos untuk pendaftaran.",
            ],
            [
                'key' => 'bot_need_image',
                'title' => 'Instruksi Kirim Format Gambar',
                'body' => "Untuk melaporkan pembayaran sewa, mohon kirim bukti transfer dalam bentuk foto atau dokumen gambar.",
            ],
            [
                'key' => 'bot_choose_invoice',
                'title' => 'Pemilihan Tagihan Multi-Invoice',
                'body' => "Anda memiliki beberapa tagihan yang belum lunas. Bukti ini untuk periode yang mana? Balas dengan angkanya:\n{{daftar_tagihan}}",
            ],
            [
                'key' => 'admin_new_proof',
                'title' => 'Notifikasi Admin Bukti Transfer Baru',
                'body' => "🔔 NOTIFIKASI BUKTI BARU (WA)\nAda bukti transfer sewa baru masuk dan menunggu verifikasi:\n• Penghuni: {{nama}}\n• Kamar: {{kamar}}\n• Tagihan: {{periode}} ({{nominal}})\nSilakan periksa di Dashboard Admin: {{url_admin}}",
            ],
        ];

        foreach ($templates as $data) {
            WaTemplate::updateOrCreate(
                ['key' => $data['key']],
                [
                    'title' => $data['title'],
                    'body' => $data['body'],
                    'is_active' => true,
                ]
            );
        }
    }
}
