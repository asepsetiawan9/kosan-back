<?php

declare(strict_types=1);

namespace App\Services\WaTemplateRenderer;

namespace App\Services;

use App\Repositories\Contracts\WaTemplateRepositoryInterface;

class WaTemplateRenderer
{
    public function __construct(
        protected WaTemplateRepositoryInterface $templateRepo
    ) {}

    /**
     * Render template by key or raw template text with given parameters.
     *
     * @param string $keyOrText Template key or raw template text
     * @param array<string, mixed> $params Placeholder variables
     */
    public function render(string $keyOrText, array $params = []): string
    {
        $body = $keyOrText;

        // If it looks like a template key (no spaces, alphanumeric/underscores)
        if (!str_contains($keyOrText, ' ') && !str_contains($keyOrText, "\n")) {
            $template = $this->templateRepo->findByKey($keyOrText);
            if ($template) {
                $body = $template->body;
            }
        }

        // Default global values if not provided
        $defaults = [
            'nama_kos' => config('app.name', 'Kosan Eksklusif'),
            'no_rekening' => 'BCA 1234567890 a/n Pengelola Kos',
            'url_admin' => url('/dashboard/wa-payments'),
        ];

        $merged = array_merge($defaults, $params);

        foreach ($merged as $placeholder => $value) {
            $valStr = (string) ($value ?? '');
            $body = str_replace([
                '{{' . $placeholder . '}}',
                '{{ ' . $placeholder . ' }}',
            ], $valStr, $body);
        }

        return trim($body);
    }

    /**
     * Get list of standard supported placeholders and their descriptions.
     *
     * @return array<string, string>
     */
    public function getSupportedPlaceholders(): array
    {
        return [
            'nama' => 'Nama lengkap penghuni',
            'kamar' => 'Nomor / Nama kamar (contoh: 101, VIP-2)',
            'periode' => 'Periode sewa (contoh: Oktober 2026)',
            'nominal' => 'Nominal tagihan terformat (contoh: Rp 1.500.000)',
            'jatuh_tempo' => 'Tanggal jatuh tempo (contoh: 15 Oktober 2026)',
            'sisa_hari' => 'Sisa hari menuju jatuh tempo (contoh: 3)',
            'no_rekening' => 'Nomor rekening pembayaran pengelola kos',
            'nama_kos' => 'Nama properti / kosan',
            'alasan_penolakan' => 'Alasan penolakan bukti transfer (khusus penolakan)',
            'daftar_tagihan' => 'Daftar nomor tagihan untuk bot',
            'url_admin' => 'Tautan langsung ke panel verifikasi pembayaran admin',
        ];
    }
}
