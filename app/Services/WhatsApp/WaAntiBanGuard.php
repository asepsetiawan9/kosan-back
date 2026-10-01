<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Anti-Ban Rate Limiter for WhatsApp Gateway.
 *
 * Provides multi-layer rate limiting to prevent WhatsApp account restrictions:
 * - Per-hour message cap
 * - Per-day message cap
 * - Business hours enforcement
 * - Unknown sender reply throttle
 * - Circuit breaker for consecutive failures
 * - Minimum inter-message delay enforcement
 */
class WaAntiBanGuard
{
    /** @var string Cache prefix for all anti-ban keys */
    private const PREFIX = 'wa_antiban:';

    /**
     * Check if sending a message is currently allowed.
     *
     * @param bool $isDirectReply If true, bypass business hours check (e.g. chatbot replying to user)
     * @return array{allowed: bool, reason: string|null, retry_after_seconds: int|null}
     */
    public function canSend(bool $isDirectReply = false): array
    {
        if (!config('services.whatsapp.antiban.enabled', true)) {
            return [
                'allowed' => true,
                'reason' => null,
                'retry_after_seconds' => null,
            ];
        }

        // 1. Circuit breaker check (highest priority - applies to all messages)
        if ($this->isCircuitOpen()) {
            $retryAfter = $this->getCircuitRetrySeconds();
            return [
                'allowed' => false,
                'reason' => "Circuit breaker aktif: terlalu banyak kegagalan berturut-turut. Auto-resume dalam {$retryAfter} detik.",
                'retry_after_seconds' => $retryAfter,
            ];
        }

        // 2. Business hours check (bypassed for direct chatbot replies to user-initiated messages)
        if (!$isDirectReply && !$this->isWithinBusinessHours()) {
            $nextWindow = $this->getNextBusinessHourStart();
            return [
                'allowed' => false,
                'reason' => "Di luar jam operasional WhatsApp ({$this->getBusinessHoursStart()}:00-{$this->getBusinessHoursEnd()}:00 WIB). Pesan akan dikirim pada {$nextWindow}.",
                'retry_after_seconds' => $this->secondsUntilNextBusinessHour(),
            ];
        }

        // 3. Hourly rate limit check
        $hourlyCount = $this->getHourlyCount();
        $hourlyMax = $this->getHourlyMax();
        if ($hourlyCount >= $hourlyMax) {
            return [
                'allowed' => false,
                'reason' => "Batas pengiriman per jam tercapai ({$hourlyCount}/{$hourlyMax}). Coba lagi di jam berikutnya.",
                'retry_after_seconds' => $this->secondsUntilNextHour(),
            ];
        }

        // 4. Daily rate limit check
        $dailyCount = $this->getDailyCount();
        $dailyMax = $this->getDailyMax();
        if ($dailyCount >= $dailyMax) {
            return [
                'allowed' => false,
                'reason' => "Batas pengiriman harian tercapai ({$dailyCount}/{$dailyMax}). Coba lagi besok pukul {$this->getBusinessHoursStart()}:00 WIB.",
                'retry_after_seconds' => $this->secondsUntilTomorrow(),
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'retry_after_seconds' => null,
        ];
    }

    /**
     * Record a successful message send.
     */
    public function recordSent(): void
    {
        $this->incrementHourly();
        $this->incrementDaily();
        $this->resetConsecutiveFailures();

        Log::debug('[WaAntiBan] Message sent recorded', [
            'hourly' => $this->getHourlyCount() . '/' . $this->getHourlyMax(),
            'daily' => $this->getDailyCount() . '/' . $this->getDailyMax(),
        ]);
    }

    /**
     * Record a failed message send attempt.
     */
    public function recordFailure(): void
    {
        $failures = $this->incrementConsecutiveFailures();
        $threshold = $this->getCircuitBreakerThreshold();

        if ($failures >= $threshold) {
            $this->openCircuit();
            Log::warning("[WaAntiBan] Circuit breaker OPENED after {$failures} consecutive failures. Pausing all sends for {$this->getCircuitCooldownMinutes()} minutes.");
        }
    }

    /**
     * Check if replying to an unknown sender is allowed (throttle).
     */
    public function canReplyToUnknown(string $phone): bool
    {
        if (!config('services.whatsapp.antiban.enabled', true)) {
            return true;
        }

        $key = self::PREFIX . 'unknown_reply:' . $phone . ':' . now('Asia/Jakarta')->toDateString();
        $count = (int) Cache::get($key, 0);
        $max = (int) config('services.whatsapp.antiban.unknown_reply_max_per_day', 2);

        return $count < $max;
    }

    /**
     * Record a reply sent to an unknown sender.
     */
    public function recordUnknownReply(string $phone): void
    {
        $key = self::PREFIX . 'unknown_reply:' . $phone . ':' . now('Asia/Jakarta')->toDateString();
        $count = (int) Cache::get($key, 0);
        Cache::put($key, $count + 1, now('Asia/Jakarta')->endOfDay());
    }

    /**
     * Get the randomized delay in seconds for the next message.
     * Uses a longer, more human-like delay range.
     */
    public function getRandomDelay(): int
    {
        $min = (int) config('services.whatsapp.antiban.delay_min', 8);
        $max = (int) config('services.whatsapp.antiban.delay_max', 20);

        return random_int(max(1, $min), max($min + 1, $max));
    }

    /**
     * Get current anti-ban status for dashboard monitoring.
     *
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        return [
            'hourly_count' => $this->getHourlyCount(),
            'hourly_max' => $this->getHourlyMax(),
            'daily_count' => $this->getDailyCount(),
            'daily_max' => $this->getDailyMax(),
            'is_within_business_hours' => $this->isWithinBusinessHours(),
            'business_hours' => $this->getBusinessHoursStart() . ':00-' . $this->getBusinessHoursEnd() . ':00 WIB',
            'circuit_breaker_open' => $this->isCircuitOpen(),
            'consecutive_failures' => $this->getConsecutiveFailures(),
            'circuit_breaker_threshold' => $this->getCircuitBreakerThreshold(),
            'delay_range' => config('services.whatsapp.antiban.delay_min', 8) . '-' . config('services.whatsapp.antiban.delay_max', 20) . 's',
            'is_sending_allowed' => $this->canSend()['allowed'],
        ];
    }

    /**
     * Manually reset the circuit breaker (admin action).
     */
    public function resetCircuitBreaker(): void
    {
        Cache::forget(self::PREFIX . 'circuit_open');
        Cache::forget(self::PREFIX . 'consecutive_failures');
        Log::info('[WaAntiBan] Circuit breaker manually reset by admin.');
    }

    // ─── Private Helpers ─────────────────────────────────────────

    private function isWithinBusinessHours(): bool
    {
        $now = now('Asia/Jakarta');
        $hour = (int) $now->format('H');

        return $hour >= $this->getBusinessHoursStart() && $hour < $this->getBusinessHoursEnd();
    }

    private function getBusinessHoursStart(): int
    {
        return (int) config('services.whatsapp.antiban.business_hours_start', 8);
    }

    private function getBusinessHoursEnd(): int
    {
        return (int) config('services.whatsapp.antiban.business_hours_end', 20);
    }

    private function getNextBusinessHourStart(): string
    {
        $now = now('Asia/Jakarta');
        $hour = (int) $now->format('H');

        if ($hour >= $this->getBusinessHoursEnd()) {
            return $now->addDay()->setHour($this->getBusinessHoursStart())->setMinute(0)->format('d/m/Y H:i');
        }

        return $now->setHour($this->getBusinessHoursStart())->setMinute(0)->format('d/m/Y H:i');
    }

    private function secondsUntilNextBusinessHour(): int
    {
        $now = now('Asia/Jakarta');
        $hour = (int) $now->format('H');

        if ($hour >= $this->getBusinessHoursEnd()) {
            $next = $now->copy()->addDay()->setHour($this->getBusinessHoursStart())->setMinute(0)->setSecond(0);
        } else {
            $next = $now->copy()->setHour($this->getBusinessHoursStart())->setMinute(0)->setSecond(0);
        }

        return max(1, (int) $now->diffInSeconds($next, false));
    }

    // ─── Hourly Limiter ──────────────────────────────────────────

    private function getHourlyMax(): int
    {
        return (int) config('services.whatsapp.antiban.hourly_max', 10);
    }

    private function getHourlyCount(): int
    {
        $key = self::PREFIX . 'hourly:' . now('Asia/Jakarta')->format('Y-m-d-H');
        return (int) Cache::get($key, 0);
    }

    private function incrementHourly(): void
    {
        $key = self::PREFIX . 'hourly:' . now('Asia/Jakarta')->format('Y-m-d-H');
        $count = (int) Cache::get($key, 0);
        Cache::put($key, $count + 1, now('Asia/Jakarta')->endOfHour()->addMinute());
    }

    private function secondsUntilNextHour(): int
    {
        $now = now('Asia/Jakarta');
        $nextHour = $now->copy()->addHour()->startOfHour();
        return max(1, (int) $now->diffInSeconds($nextHour, false));
    }

    // ─── Daily Limiter ───────────────────────────────────────────

    private function getDailyMax(): int
    {
        return (int) config('services.whatsapp.antiban.daily_max', 50);
    }

    private function getDailyCount(): int
    {
        $key = self::PREFIX . 'daily:' . now('Asia/Jakarta')->toDateString();
        return (int) Cache::get($key, 0);
    }

    private function incrementDaily(): void
    {
        $key = self::PREFIX . 'daily:' . now('Asia/Jakarta')->toDateString();
        $count = (int) Cache::get($key, 0);
        Cache::put($key, $count + 1, now('Asia/Jakarta')->endOfDay()->addMinute());
    }

    private function secondsUntilTomorrow(): int
    {
        $now = now('Asia/Jakarta');
        $tomorrow = $now->copy()->addDay()->startOfDay()->addHours($this->getBusinessHoursStart());
        return max(1, (int) $now->diffInSeconds($tomorrow, false));
    }

    // ─── Circuit Breaker ─────────────────────────────────────────

    private function getCircuitBreakerThreshold(): int
    {
        return (int) config('services.whatsapp.antiban.circuit_breaker_threshold', 3);
    }

    private function getCircuitCooldownMinutes(): int
    {
        return (int) config('services.whatsapp.antiban.circuit_cooldown_minutes', 30);
    }

    private function isCircuitOpen(): bool
    {
        return (bool) Cache::get(self::PREFIX . 'circuit_open', false);
    }

    private function openCircuit(): void
    {
        Cache::put(
            self::PREFIX . 'circuit_open',
            true,
            now()->addMinutes($this->getCircuitCooldownMinutes())
        );
    }

    private function getCircuitRetrySeconds(): int
    {
        // Approximate — TTL-based expiry
        return $this->getCircuitCooldownMinutes() * 60;
    }

    private function getConsecutiveFailures(): int
    {
        return (int) Cache::get(self::PREFIX . 'consecutive_failures', 0);
    }

    private function incrementConsecutiveFailures(): int
    {
        $key = self::PREFIX . 'consecutive_failures';
        $count = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $count, now()->addHours(2));
        return $count;
    }

    private function resetConsecutiveFailures(): void
    {
        Cache::forget(self::PREFIX . 'consecutive_failures');
    }
}
