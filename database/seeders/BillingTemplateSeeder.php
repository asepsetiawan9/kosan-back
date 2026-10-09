<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BillingTemplate;
use Illuminate\Database\Seeder;

class BillingTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'key' => 'tagihan_bulanan',
                'title' => 'Pengingat Tagihan Bulanan',
                'body' => "Assalamualaikum {{nama}} 🙏\n\nPengingat tagihan kos *{{nama_kos}}* Kamar {{kamar}} periode *{{periode}}*:\n💰 Nominal: *Rp {{nominal}}*\n📅 Jatuh tempo: *{{jatuh_tempo}}*\n\nSilakan transfer ke:\n🏦 {{no_rekening}}\n\nTerima kasih atas kerjasamanya 🤝",
                'is_active' => true,
            ],
            [
                'key' => 'jatuh_tempo_hari_ini',
                'title' => 'Tagihan Jatuh Tempo Hari Ini',
                'body' => "Halo {{nama}}, hari ini tanggal *{{jatuh_tempo}}* adalah jatuh tempo tagihan kos Kamar {{kamar}} sebesar *Rp {{nominal}}* periode {{periode}}.\n\nMohon segera transfer ke {{no_rekening}}.\nTerima kasih 🙏",
                'is_active' => true,
            ],
            [
                'key' => 'tunggakan',
                'title' => 'Peringatan Tunggakan Tagihan',
                'body' => "Assalamualaikum {{nama}} 🙏\n\nMohon maaf mengingatkan, tagihan kos Kamar {{kamar}} periode *{{periode}}* sebesar *Rp {{nominal}}* sudah melewati jatuh tempo ({{jatuh_tempo}}).\n\nMohon segera dilunasi ke {{no_rekening}}.\nTerima kasih atas perhatiannya 🤝",
                'is_active' => true,
            ],
            [
                'key' => 'ucapan_terima_kasih',
                'title' => 'Konfirmasi Pembayaran Diterima',
                'body' => "Halo {{nama}}, terima kasih atas pembayaran tagihan kos Kamar {{kamar}} periode {{periode}} 🙏\n\nPembayaran sebesar *Rp {{nominal}}* sudah kami terima.\nSemoga betah tinggal di {{nama_kos}} 😊",
                'is_active' => true,
            ],
        ];

        foreach ($templates as $tpl) {
            BillingTemplate::updateOrCreate(
                ['key' => $tpl['key']],
                $tpl
            );
        }
    }
}
