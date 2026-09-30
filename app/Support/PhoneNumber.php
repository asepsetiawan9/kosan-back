<?php

declare(strict_types=1);

namespace App\Support;

class PhoneNumber
{
    /**
     * Normalize a phone number to standard Indonesian format: 62xxxxxxxxxx
     */
    public static function normalize(string $phone): string
    {
        // Strip all non-digit characters (+, -, spaces, parentheses, etc.)
        $clean = preg_replace('/[^\d]/', '', $phone) ?? '';

        if ($clean === '') {
            return '';
        }

        // 08xxxx -> 628xxxx
        if (str_starts_with($clean, '0')) {
            $clean = '62' . substr($clean, 1);
        } elseif (str_starts_with($clean, '8')) {
            // 8xxxx -> 628xxxx
            $clean = '62' . $clean;
        }

        return $clean;
    }

    /**
     * Check if the phone number is a valid Indonesian mobile phone number (10 - 15 digits starting with 628).
     */
    public static function isValid(string $phone): bool
    {
        $normalized = self::normalize($phone);

        return (bool) preg_match('/^628[0-9]{8,12}$/', $normalized);
    }

    /**
     * Format a phone number for neat UI display (+62 8xx-xxxx-xxxx).
     */
    public static function formatDisplay(string $phone): string
    {
        $normalized = self::normalize($phone);

        if (!str_starts_with($normalized, '62')) {
            return $phone;
        }

        $rest = substr($normalized, 2);
        if (strlen($rest) < 8) {
            return '+' . $normalized;
        }

        // Example: +62 812-3456-7890
        $prefix = substr($rest, 0, 3);
        $mid = substr($rest, 3, 4);
        $tail = substr($rest, 7);

        return "+62 {$prefix}-{$mid}-{$tail}";
    }
}
