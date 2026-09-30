<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\DTO;

use DateTimeInterface;

class IncomingMessage
{
    public function __construct(
        public readonly string $providerMessageId,
        public readonly string $from,
        public readonly string $type, // 'text' | 'image' | 'document' | 'other'
        public readonly ?string $text = null,
        public readonly ?array $media = null, // ['url' => ..., 'mime' => ..., 'filename' => ...]
        public readonly ?DateTimeInterface $timestamp = null,
        public readonly array $raw = []
    ) {}
}
