<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\DTO;

class SendResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $errorMessage = null,
        public readonly array $rawResponse = []
    ) {}

    public static function success(string $providerMessageId, array $rawResponse = []): self
    {
        return new self(
            success: true,
            providerMessageId: $providerMessageId,
            rawResponse: $rawResponse
        );
    }

    public static function failed(string $errorMessage, array $rawResponse = []): self
    {
        return new self(
            success: false,
            errorMessage: $errorMessage,
            rawResponse: $rawResponse
        );
    }
}
