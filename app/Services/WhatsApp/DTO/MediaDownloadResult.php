<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\DTO;

class MediaDownloadResult
{
    public function __construct(
        public readonly string $content,
        public readonly string $mimeType,
        public readonly ?string $filename = null,
        public readonly ?int $fileSize = null
    ) {}
}
