<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\WaReminderLog;
use App\Models\WaReminderRule;
use App\Repositories\Contracts\WaReminderRuleRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WaReminderService
{
    public function __construct(
        protected WaReminderRuleRepositoryInterface $ruleRepo,
        protected WaTemplateRenderer $renderer,
        protected WaMessageService $messageService
    ) {}

    /**
     * Run the automated reminder scheduler logic for active rules.
     *
     * @param bool $dryRun If true, simulate and do not dispatch messages or create persistent logs
     * @param Carbon|null $targetDate Target execution date (defaults to today WIB)
     * @return array<string, mixed>
     */
    public function runReminders(bool $dryRun = false, ?Carbon $targetDate = null): array
    {
        $today = ($targetDate ?? Carbon::today('Asia/Jakarta'))->startOfDay();
        $todayDateStr = $today->toDateString();

        $activeRules = $this->ruleRepo->getActive();

        $totalInvoicesChecked = 0;
        $remindersSent = 0;
        $skippedAlreadySent = 0;
        $skippedPendingPayment = 0;
        $skippedOptedOut = 0;
        $items = [];

        foreach ($activeRules as $rule) {
            // Determine target invoice due date
            $targetDueDate = match ($rule->trigger_type) {
                'before_due' => $today->copy()->addDays($rule->offset_days)->toDateString(),
                'on_due' => $todayDateStr,
                'after_due' => $today->copy()->subDays($rule->offset_days)->toDateString(),
                default => $todayDateStr,
            };

            // Query invoices with due date matching target and unpaid status
            $invoices = Invoice::with(['tenancy.user', 'tenancy.room.property', 'payments'])
                ->whereIn('status', ['belum_bayar', 'sebagian_dibayar', 'terlambat'])
                ->whereDate('due_date', $targetDueDate)
                ->get();

            foreach ($invoices as $invoice) {
                $totalInvoicesChecked++;
                $tenancy = $invoice->tenancy;

                if (!$tenancy) {
                    continue;
                }

                $tenantUser = $tenancy->user;
                $phone = $tenantUser?->wa_number ?? $tenantUser?->phone ?? $tenancy->tenant_phone;

                if (empty($phone)) {
                    continue;
                }

                // 1. Check if tenant has opted out of WhatsApp reminders
                if ($tenantUser && $tenantUser->wa_opt_in === false) {
                    $skippedOptedOut++;
                    $items[] = [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'tenant_name' => $tenancy->tenant_name,
                        'phone' => $phone,
                        'rule_name' => $rule->name,
                        'template_key' => $rule->template_key,
                        'due_date' => $invoice->due_date->format('d/m/Y'),
                        'status' => 'skipped',
                        'skip_reason' => 'opted_out',
                        'message' => 'Penghuni telah menonaktifkan notifikasi WhatsApp (opt-out).',
                    ];
                    continue;
                }

                // 2. Check if invoice already has a pending payment proof (do not harass tenant!)
                $hasPendingPayment = $invoice->payments->contains(function ($payment) {
                    return $payment->status === 'pending';
                });

                if ($hasPendingPayment) {
                    $skippedPendingPayment++;
                    $items[] = [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'tenant_name' => $tenancy->tenant_name,
                        'phone' => $phone,
                        'rule_name' => $rule->name,
                        'template_key' => $rule->template_key,
                        'due_date' => $invoice->due_date->format('d/m/Y'),
                        'status' => 'skipped',
                        'skip_reason' => 'pending_payment',
                        'message' => 'Terdapat pembayaran bukti transfer yang sedang menunggu verifikasi admin.',
                    ];
                    continue;
                }

                // 3. Deduplication check: check if already sent for this invoice, rule, and date
                $alreadyLogged = WaReminderLog::where('invoice_id', $invoice->id)
                    ->where('rule_id', $rule->id)
                    ->whereDate('sent_for_date', $todayDateStr)
                    ->exists();

                if ($alreadyLogged) {
                    $skippedAlreadySent++;
                    $items[] = [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'tenant_name' => $tenancy->tenant_name,
                        'phone' => $phone,
                        'rule_name' => $rule->name,
                        'template_key' => $rule->template_key,
                        'due_date' => $invoice->due_date->format('d/m/Y'),
                        'status' => 'skipped',
                        'skip_reason' => 'already_sent',
                        'message' => 'Pengingat untuk aturan dan tanggal ini sudah pernah dikirim sebelumnya.',
                    ];
                    continue;
                }

                // Calculate parameters
                $remaining = max(0.0, (float) $invoice->total_amount - (float) $invoice->paid_amount);
                $formattedAmount = 'Rp ' . number_format($remaining, 0, ',', '.');
                $daysRemaining = max(0, (int) $today->diffInDays($invoice->due_date, false));

                $property = $tenancy->room?->property;
                $roomNumber = $tenancy->room?->room_number ?? '-';
                $propertyName = $property?->name ?? config('app.name', 'Kosan Eksklusif');
                $ownerName = $property?->owner_name ?? 'Pengelola Kos';
                $rekening = 'BCA 1234567890 a/n ' . $ownerName;

                $params = [
                    'nama' => $tenancy->tenant_name ?? 'Penghuni',
                    'kamar' => $roomNumber,
                    'periode' => $invoice->period,
                    'nominal' => $formattedAmount,
                    'jatuh_tempo' => $invoice->due_date->format('d/m/Y'),
                    'sisa_hari' => (string) $daysRemaining,
                    'nama_kos' => $propertyName,
                    'no_rekening' => $rekening,
                ];

                $body = $this->renderer->render($rule->template_key, $params);

                if ($dryRun) {
                    $items[] = [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'tenant_name' => $tenancy->tenant_name,
                        'phone' => $phone,
                        'rule_name' => $rule->name,
                        'template_key' => $rule->template_key,
                        'due_date' => $invoice->due_date->format('d/m/Y'),
                        'nominal' => $formattedAmount,
                        'status' => 'ready',
                        'preview_body' => $body,
                    ];
                } else {
                    // Send message and create deduplication log in transaction
                    try {
                        DB::transaction(function () use (
                            $invoice,
                            $rule,
                            $todayDateStr,
                            $phone,
                            $body,
                            &$remindersSent,
                            &$items,
                            $tenancy,
                            $formattedAmount
                        ) {
                            $log = WaReminderLog::create([
                                'invoice_id' => $invoice->id,
                                'rule_id' => $rule->id,
                                'sent_for_date' => $todayDateStr,
                            ]);

                            $waMessage = $this->messageService->send($phone, $body, [
                                'template_key' => $rule->template_key,
                                'related_type' => Invoice::class,
                                'related_id' => $invoice->id,
                            ]);

                            $log->update(['wa_message_id' => $waMessage->id]);

                            $remindersSent++;
                            $items[] = [
                                'invoice_id' => $invoice->id,
                                'invoice_number' => $invoice->invoice_number,
                                'tenant_name' => $tenancy->tenant_name,
                                'phone' => $phone,
                                'rule_name' => $rule->name,
                                'template_key' => $rule->template_key,
                                'due_date' => $invoice->due_date->format('d/m/Y'),
                                'nominal' => $formattedAmount,
                                'status' => 'sent',
                                'wa_message_id' => $waMessage->id,
                            ];
                        });
                    } catch (\Throwable $e) {
                        Log::error('[WA_REMINDER_ERROR] Failed sending reminder for invoice ' . $invoice->id, [
                            'error' => $e->getMessage(),
                            'rule_id' => $rule->id,
                        ]);
                    }
                }
            }
        }

        return [
            'target_date' => $todayDateStr,
            'dry_run' => $dryRun,
            'rules_evaluated' => $activeRules->count(),
            'invoices_checked' => $totalInvoicesChecked,
            'reminders_sent' => $remindersSent,
            'skipped' => [
                'already_sent' => $skippedAlreadySent,
                'pending_payment' => $skippedPendingPayment,
                'opted_out' => $skippedOptedOut,
            ],
            'items' => $items,
        ];
    }

    /**
     * Get paginated logs of sent reminders with relations.
     *
     * @param array<string, mixed> $filters
     */
    public function getRecentLogs(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = WaReminderLog::with(['invoice.tenancy', 'rule.template', 'waMessage'])
            ->latest('created_at');

        if (!empty($filters['rule_id'])) {
            $query->where('rule_id', $filters['rule_id']);
        }

        if (!empty($filters['invoice_id'])) {
            $query->where('invoice_id', $filters['invoice_id']);
        }

        if (!empty($filters['date'])) {
            $query->whereDate('sent_for_date', $filters['date']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->whereHas('invoice.tenancy', function ($q) use ($search) {
                $q->where('tenant_name', 'like', $search)
                    ->orWhere('tenant_phone', 'like', $search);
            })->orWhereHas('invoice', function ($q) use ($search) {
                $q->where('invoice_number', 'like', $search);
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * Get overview summary of reminder rules and activity.
     *
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        $todayDateStr = Carbon::today('Asia/Jakarta')->toDateString();

        $totalRules = WaReminderRule::count();
        $activeRules = WaReminderRule::active()->count();
        $sentToday = WaReminderLog::whereDate('sent_for_date', $todayDateStr)->count();

        return [
            'total_rules' => $totalRules,
            'active_rules' => $activeRules,
            'reminders_sent_today' => $sentToday,
            'timezone' => 'Asia/Jakarta',
        ];
    }
}
