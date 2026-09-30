<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\WhatsAppProviderInterface;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaReminderLog;
use App\Models\WaReminderRule;
use App\Repositories\Contracts\WaMessageRepositoryInterface;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WaHealthCheckService
{
    public function __construct(
        protected WhatsAppProviderInterface $provider,
        protected WaMessageRepositoryInterface $messageRepo
    ) {}

    /**
     * Perform comprehensive health diagnostics across all WhatsApp subsystems.
     *
     * @return array<string, mixed>
     */
    public function checkHealth(): array
    {
        $startedAt = microtime(true);

        // 1. Check Provider Connectivity
        $providerStatus = $this->checkProvider();

        // 2. Check Database Connectivity & Latency
        $dbStatus = $this->checkDatabase();

        // 3. Check Queue Backlog & Failures
        $queueStatus = $this->checkQueue();

        // 4. Check 24-Hour Message Delivery Metrics
        $metricsStatus = $this->checkMessageMetrics();

        // 5. Check Scheduler & Reminder Engine
        $schedulerStatus = $this->checkScheduler();

        // 6. Calculate Overall System Health State
        $overallStatus = $this->determineOverallStatus(
            $providerStatus,
            $dbStatus,
            $queueStatus,
            $metricsStatus
        );

        $executionTimeMs = round((microtime(true) - $startedAt) * 1000, 2);

        return [
            'status' => $overallStatus,
            'timestamp' => now()->toIso8601String(),
            'execution_time_ms' => $executionTimeMs,
            'checks' => [
                'provider' => $providerStatus,
                'database' => $dbStatus,
                'queue' => $queueStatus,
                'messages' => $metricsStatus,
                'scheduler' => $schedulerStatus,
            ],
            'system' => [
                'app_env' => config('app.env'),
                'app_url' => config('app.url'),
                'timezone' => config('app.timezone', 'Asia/Jakarta'),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
            ],
        ];
    }

    /**
     * Check WhatsApp adapter provider connection.
     */
    protected function checkProvider(): array
    {
        try {
            $status = $this->provider->checkConnection();
            $isConnected = ($status['status'] ?? '') === 'connected';

            return [
                'status' => $isConnected ? 'ok' : 'error',
                'connected' => $isConnected,
                'provider' => $status['provider'] ?? config('services.whatsapp.provider', 'unknown'),
                'device' => $status['device'] ?? null,
                'phone' => $status['phone'] ?? null,
                'quota' => $status['quota'] ?? null,
                'message' => $status['message'] ?? ($isConnected ? 'Koneksi ke gateway WhatsApp aktif' : 'Device WhatsApp terputus atau token tidak valid'),
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'connected' => false,
                'provider' => config('services.whatsapp.provider', 'unknown'),
                'message' => 'Gagal menghubungi gateway provider: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check Database connectivity and response latency.
     */
    protected function checkDatabase(): array
    {
        $dbStart = microtime(true);
        try {
            DB::select('SELECT 1');
            $latencyMs = round((microtime(true) - $dbStart) * 1000, 2);

            return [
                'status' => 'ok',
                'driver' => DB::getDriverName(),
                'latency_ms' => $latencyMs,
                'message' => 'Koneksi basis data beroperasi normal',
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'driver' => DB::getDriverName(),
                'latency_ms' => null,
                'message' => 'Koneksi basis data terganggu: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check Queue status, pending jobs and failed jobs.
     */
    protected function checkQueue(): array
    {
        $pendingJobs = 0;
        $failedJobs = 0;
        $driver = (string) config('queue.default', 'database');

        if (Schema::hasTable('jobs')) {
            $pendingJobs = DB::table('jobs')->count();
        }

        if (Schema::hasTable('failed_jobs')) {
            $failedJobs = DB::table('failed_jobs')->count();
        }

        $queuedWaMessages = WaMessage::where('status', 'queued')->count();

        $status = 'ok';
        $message = 'Antrean pengiriman pesan berjalan normal';

        if ($failedJobs > 0) {
            $status = 'warning';
            $message = "Terdapat {$failedJobs} pekerjaan gagal pada queue worker.";
        } elseif ($pendingJobs > 50 || $queuedWaMessages > 20) {
            $status = 'warning';
            $message = "Terjadi penumpukan antrean: {$pendingJobs} pekerjaan tertunda ({$queuedWaMessages} pesan WA).";
        }

        return [
            'status' => $status,
            'driver' => $driver,
            'pending_jobs' => $pendingJobs,
            'failed_jobs' => $failedJobs,
            'queued_messages' => $queuedWaMessages,
            'message' => $message,
        ];
    }

    /**
     * Check message transmission volume & failure rate over the last 24 hours.
     */
    protected function checkMessageMetrics(): array
    {
        $sent24h = $this->messageRepo->getRecentSentCount(24);
        $failed24h = $this->messageRepo->getRecentFailedCount(24);
        $total24h = $sent24h + $failed24h;

        $failureRate = $total24h > 0 ? round(($failed24h / $total24h) * 100, 1) : 0.0;
        $hasHighFailureRate = $failed24h >= 5;

        $status = 'ok';
        $message = 'Tingkat transmisi pesan dalam batas aman';

        if ($hasHighFailureRate) {
            $status = 'warning';
            $message = "Peringatan: {$failed24h} pesan gagal dalam 24 jam terakhir (tingkat kegagalan {$failureRate}%).";
        }

        return [
            'status' => $status,
            'sent_last_24h' => $sent24h,
            'failed_last_24h' => $failed24h,
            'total_last_24h' => $total24h,
            'failure_rate_percent' => $failureRate,
            'high_failure_alert' => $hasHighFailureRate,
            'message' => $message,
        ];
    }

    /**
     * Check Scheduler status and last reminder activity.
     */
    protected function checkScheduler(): array
    {
        $activeRulesCount = WaReminderRule::where('is_active', true)->count();
        $latestReminderLog = WaReminderLog::latest()->first();
        $activeConversationsCount = WaConversation::where('state', '!=', 'idle')->count();

        return [
            'status' => 'ok',
            'active_rules_count' => $activeRulesCount,
            'active_conversations_count' => $activeConversationsCount,
            'last_reminder_run_at' => $latestReminderLog?->created_at?->toIso8601String(),
            'message' => "Scheduler aktif dengan {$activeRulesCount} aturan pengingat tagihan beroperasi.",
        ];
    }

    /**
     * Determine aggregate health status: healthy | degraded | unhealthy.
     */
    protected function determineOverallStatus(
        array $provider,
        array $db,
        array $queue,
        array $metrics
    ): string {
        if (($db['status'] ?? '') === 'error' || ($provider['status'] ?? '') === 'error') {
            return 'unhealthy';
        }

        if (
            ($queue['status'] ?? '') === 'warning' ||
            ($metrics['status'] ?? '') === 'warning'
        ) {
            return 'degraded';
        }

        return 'healthy';
    }
}
