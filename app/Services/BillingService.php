<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BillingLog;
use App\Models\BillingTemplate;
use App\Models\Invoice;
use App\Models\Tenancy;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class BillingService
{
    /**
     * Get billing targets based on active tenancies and unpaid/latest invoices.
     */
    public function getTargets(array $filters = []): array
    {
        $query = Tenancy::query()
            ->with([
                'room.property',
                'user',
                'invoices' => function ($q) {
                    $q->orderBy('due_date', 'asc')->orderBy('created_at', 'desc');
                },
                'invoices.payments' => function ($q) {
                    $q->where('status', 'berhasil')->latest();
                },
            ])
            ->where('status', 'aktif');

        if (!empty($filters['property_id'])) {
            $query->whereHas('room', function ($q) use ($filters) {
                $q->where('property_id', $filters['property_id']);
            });
        }

        $tenancies = $query->get();
        $today = Carbon::today();

        $targets = $tenancies->map(function (Tenancy $tenancy) use ($today) {
            // Find the most urgent unpaid invoice or the latest invoice
            $unpaidInvoice = $tenancy->invoices->first(function (Invoice $inv) {
                return in_array($inv->status, ['belum_bayar', 'sebagian_dibayar', 'lewat_jatuh_tempo', 'menunggu_verifikasi'], true);
            });

            $invoice = $unpaidInvoice ?: $tenancy->invoices->first();

            // Calculate due date
            $dueDate = null;
            $daysUntilDue = null;

            if ($invoice && $invoice->due_date) {
                $dueDate = Carbon::parse($invoice->due_date);
            } elseif ($tenancy->billing_due_day) {
                $dueDay = min(max((int) $tenancy->billing_due_day, 1), 28);
                $dueDate = Carbon::create($today->year, $today->month, $dueDay);
                // If already passed in this month and no invoice exists, next due is next month
                if ($dueDate->isPast() && !$invoice) {
                    $dueDate->addMonth();
                }
            }

            if ($dueDate) {
                $daysUntilDue = (int) $today->diffInDays($dueDate, false);
            }

            // Find last successful payment date
            $lastPaymentDate = null;
            foreach ($tenancy->invoices as $inv) {
                $lastPayment = $inv->payments->first();
                if ($lastPayment) {
                    $lastPaymentDate = Carbon::parse($lastPayment->created_at)->format('Y-m-d');
                    break;
                }
            }

            $amount = $invoice 
                ? (float) $invoice->remaining_amount 
                : (float) ($tenancy->room?->price ?? 0);

            $tenantName = $tenancy->tenant_name ?: ($tenancy->user?->name ?? 'Penghuni');
            $tenantPhone = $tenancy->tenant_phone ?: ($tenancy->user?->phone ?? '');
            $roomNumber = $tenancy->room?->room_number ?? '-';
            $roomName = $tenancy->room?->name ?: "Kamar {$roomNumber}";
            $location = $tenancy->room?->property?->name ?: ($tenancy->room?->property?->address ?? 'Kosan');

            return [
                'tenancy_id' => $tenancy->id,
                'tenant_name' => $tenantName,
                'tenant_phone' => $tenantPhone,
                'room_number' => $roomNumber,
                'room_name' => $roomName,
                'location' => $location,
                'invoice_id' => $invoice?->id,
                'invoice_period' => $invoice?->period ?: Carbon::now()->translatedFormat('F Y'),
                'invoice_amount' => $amount,
                'invoice_status' => $invoice?->status ?? 'belum_bayar',
                'due_date' => $dueDate ? $dueDate->format('Y-m-d') : null,
                'days_until_due' => $daysUntilDue,
                'last_payment_date' => $lastPaymentDate,
            ];
        });

        // Filter by status if provided
        if (!empty($filters['status'])) {
            $status = $filters['status'];
            $targets = $targets->filter(function ($target) use ($status) {
                if ($status === 'jatuh_tempo_hari_ini') {
                    return $target['days_until_due'] === 0;
                }
                if ($status === 'mendekati') {
                    return $target['days_until_due'] !== null && $target['days_until_due'] > 0 && $target['days_until_due'] <= 3;
                }
                if ($status === 'tunggakan') {
                    return ($target['days_until_due'] !== null && $target['days_until_due'] < 0) || $target['invoice_status'] === 'lewat_jatuh_tempo';
                }
                if ($status === 'lunas') {
                    return $target['invoice_status'] === 'lunas';
                }
                return true;
            });
        }

        // Search filter
        if (!empty($filters['search'])) {
            $keyword = mb_strtolower(trim($filters['search']));
            $targets = $targets->filter(function ($t) use ($keyword) {
                return str_contains(mb_strtolower($t['tenant_name']), $keyword)
                    || str_contains(mb_strtolower($t['tenant_phone']), $keyword)
                    || str_contains(mb_strtolower($t['room_number']), $keyword)
                    || str_contains(mb_strtolower($t['location']), $keyword);
            });
        }

        return $targets->values()->all();
    }

    /**
     * Render a billing template with dynamic placeholders.
     */
    public function renderTemplate(BillingTemplate $template, Tenancy $tenancy, ?Invoice $invoice = null): string
    {
        $tenancy->loadMissing(['room.property', 'user']);

        $nominal = $invoice ? (float) $invoice->remaining_amount : (float) ($tenancy->room?->price ?? 0);
        $formattedNominal = number_format($nominal, 0, ',', '.');

        $dueDate = null;
        if ($invoice && $invoice->due_date) {
            $dueDate = Carbon::parse($invoice->due_date);
        } elseif ($tenancy->billing_due_day) {
            $dueDay = min(max((int) $tenancy->billing_due_day, 1), 28);
            $dueDate = Carbon::create(Carbon::today()->year, Carbon::today()->month, $dueDay);
        }

        $formattedDueDate = $dueDate ? $dueDate->translatedFormat('d F Y') : '-';

        $sisaHari = '-';
        if ($dueDate) {
            $diff = (int) Carbon::today()->diffInDays($dueDate, false);
            if ($diff === 0) {
                $sisaHari = 'Hari ini';
            } elseif ($diff > 0) {
                $sisaHari = "{$diff} hari lagi";
            } else {
                $terlewat = abs($diff);
                $sisaHari = "Terlewat {$terlewat} hari";
            }
        }

        $namaKos = $tenancy->room?->property?->name ?: config('app.name', 'Kosan');
        $rekening = config('services.billing.bank_account', env('BILLING_BANK_ACCOUNT', 'BCA 1234567890 a/n Pengelola Kos'));

        $placeholders = [
            '{{nama}}' => $tenancy->tenant_name ?: ($tenancy->user?->name ?? 'Penghuni'),
            '{{kamar}}' => $tenancy->room?->room_number ?? '-',
            '{{periode}}' => $invoice?->period ?: Carbon::now()->translatedFormat('F Y'),
            '{{nominal}}' => $formattedNominal,
            '{{jatuh_tempo}}' => $formattedDueDate,
            '{{sisa_hari}}' => $sisaHari,
            '{{no_rekening}}' => $rekening,
            '{{nama_kos}}' => $namaKos,
        ];

        return strtr($template->body, $placeholders);
    }

    /**
     * Generate direct WhatsApp Web click-to-chat URL.
     */
    public function generateWhatsAppLink(string $phone, string $message): string
    {
        $normalized = PhoneNumber::normalize($phone);

        return 'https://wa.me/' . $normalized . '?text=' . rawurlencode($message);
    }

    /**
     * Get billing summary statistics.
     */
    public function getSummary(): array
    {
        $allTargets = $this->getTargets();

        $jatuhTempoHariIni = 0;
        $mendekati = 0;
        $tunggakan = 0;
        $lunas = 0;

        foreach ($allTargets as $target) {
            if ($target['invoice_status'] === 'lunas') {
                $lunas++;
                continue;
            }

            if ($target['days_until_due'] === 0) {
                $jatuhTempoHariIni++;
            } elseif ($target['days_until_due'] !== null && $target['days_until_due'] > 0 && $target['days_until_due'] <= 3) {
                $mendekati++;
            } elseif (($target['days_until_due'] !== null && $target['days_until_due'] < 0) || $target['invoice_status'] === 'lewat_jatuh_tempo') {
                $tunggakan++;
            }
        }

        // Count invoices marked lunas in the current month
        $currentMonth = Carbon::now()->format('Y-m');
        $lunasBulanIni = Invoice::where('status', 'lunas')
            ->where('updated_at', 'like', "{$currentMonth}%")
            ->count();

        return [
            'jatuh_tempo_hari_ini' => $jatuhTempoHariIni,
            'mendekati' => $mendekati,
            'tunggakan' => $tunggakan,
            'lunas_bulan_ini' => max($lunas, $lunasBulanIni),
            'total_target' => count($allTargets),
        ];
    }

    /**
     * Log a billing notification event.
     */
    public function logBilling(array $data, string $adminId): BillingLog
    {
        return BillingLog::create([
            'tenancy_id' => $data['tenancy_id'],
            'invoice_id' => $data['invoice_id'] ?? null,
            'template_id' => $data['template_id'] ?? null,
            'rendered_msg' => $data['rendered_msg'],
            'phone_target' => $data['phone_target'],
            'channel' => $data['channel'] ?? 'wa_web',
            'admin_id' => $adminId,
        ]);
    }

    /**
     * Get paginated billing history.
     */
    public function getHistory(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = BillingLog::query()
            ->with(['tenancy.room.property', 'invoice', 'template', 'admin'])
            ->latest();

        if (!empty($filters['tenancy_id'])) {
            $query->where('tenancy_id', $filters['tenancy_id']);
        }

        if (!empty($filters['template_id'])) {
            $query->where('template_id', $filters['template_id']);
        }

        if (!empty($filters['channel'])) {
            $query->where('channel', $filters['channel']);
        }

        return $query->paginate($perPage);
    }
}
