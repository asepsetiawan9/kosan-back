<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\WhatsApp\DTO\IncomingMessage;
use App\Services\WhatsApp\DTO\MediaDownloadResult;
use App\Services\WhatsApp\DTO\SendResult;

interface WhatsAppProviderInterface
{
    /**
     * Send text message to a destination phone number.
     *
     * @param string $to Normalized phone number (62xxxxxxxxxx)
     * @param string $text Message content
     * @param array<string, mixed> $opts Additional provider options
     */
    public function sendText(string $to, string $text, array $opts = []): SendResult;

    /**
     * Send image message to a destination phone number.
     */
    public function sendImage(string $to, string $imageUrl, ?string $caption = null): SendResult;

    /**
     * Normalize provider incoming webhook payload into a standardized IncomingMessage DTO.
     */
    public function parseIncomingWebhook(array $rawRequest): ?IncomingMessage;

    /**
     * Download binary media content from an incoming message.
     */
    public function downloadMedia(IncomingMessage $msg): MediaDownloadResult;

    /**
     * Verify incoming webhook authenticity (signature/token/IP).
     */
    public function verifyWebhook(array $rawRequest): bool;

    /**
     * Check provider device connection status and metadata.
     *
     * @return array<string, mixed>
     */
    public function checkConnection(): array;
}
