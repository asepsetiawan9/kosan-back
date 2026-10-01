<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

/**
 * Message Humanizer for WhatsApp Outbound Messages.
 *
 * Prevents automated spam pattern detection by introducing subtle, human-like
 * variations into outbound messages:
 * 1. Cryptographic hash diversification via invisible zero-width jitter (\u{200B}, \u{200C}, \u{2060})
 * 2. Greeting variations matching time of day
 * 3. Polite closing jitter
 */
class WaMessageHumanizer
{
    /** @var array<int, string> Invisible unicode characters for anti-fingerprinting */
    private const ZERO_WIDTH_SEEDS = [
        "\u{200B}", // Zero-width space
        "\u{200C}", // Zero-width non-joiner
        "\u{200D}", // Zero-width joiner
        "\u{2060}", // Word joiner
    ];

    /**
     * Humanize a message body with anti-pattern variations.
     *
     * @param string $body Original message body
     * @return string Humanized message body
     */
    public function humanize(string $body): string
    {
        if (empty(trim($body))) {
            return $body;
        }

        if (!config('services.whatsapp.antiban.humanize_messages', true)) {
            return $body;
        }

        $result = $body;

        // 1. Time-aware greeting adjustment if message starts with standard "Halo"
        $result = $this->adaptGreeting($result);

        // 2. Subtle polite closing variation if ending with standard "Terima kasih 🙏"
        $result = $this->adaptClosing($result);

        // 3. Append subtle invisible jitter to make message payload hash unique
        $result = $this->injectInvisibleJitter($result);

        return $result;
    }

    /**
     * Adapt greeting to feel more natural and human-like.
     */
    private function adaptGreeting(string $text): string
    {
        // Only adapt if message begins with "Halo "
        if (!str_starts_with($text, 'Halo ')) {
            return $text;
        }

        $hour = (int) now('Asia/Jakarta')->format('H');
        $timeGreeting = match (true) {
            $hour >= 5 && $hour < 11 => 'Selamat pagi',
            $hour >= 11 && $hour < 15 => 'Selamat siang',
            $hour >= 15 && $hour < 18 => 'Selamat sore',
            default => 'Selamat malam',
        };

        $greetings = [
            'Halo',
            'Hai',
            $timeGreeting,
        ];

        // 50% chance to replace "Halo " with varied greeting
        if (random_int(1, 2) === 1) {
            $chosen = $greetings[array_rand($greetings)];
            return $chosen . ' ' . substr($text, 5);
        }

        return $text;
    }

    /**
     * Adapt closing phrases to add natural variation.
     */
    private function adaptClosing(string $text): string
    {
        $closings = [
            'Terima kasih 🙏',
            'Terima kasih banyak 🙏',
            'Terima kasih! 🙏',
            'Terima kasih atas perhatiannya 🙏',
        ];

        if (str_ends_with(trim($text), 'Terima kasih 🙏')) {
            if (random_int(1, 2) === 1) {
                $chosen = $closings[array_rand($closings)];
                $trimmed = rtrim($text);
                return substr($trimmed, 0, -strlen('Terima kasih 🙏')) . $chosen;
            }
        }

        return $text;
    }

    /**
     * Injects an invisible zero-width jitter sequence so identical messages
     * have unique payload signatures and avoid algorithmic deduplication filters.
     */
    private function injectInvisibleJitter(string $text): string
    {
        $seeds = self::ZERO_WIDTH_SEEDS;
        $seedLength = random_int(2, 5);
        $jitter = '';

        for ($i = 0; $i < $seedLength; $i++) {
            $jitter .= $seeds[array_rand($seeds)];
        }

        // Append to the end of message so it never affects text rendering
        return $text . $jitter;
    }
}
