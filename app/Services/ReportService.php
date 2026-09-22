<?php

declare(strict_types=1);

namespace App\Services;

use App\Exports\FinancialReportExport;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Room;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportService
{
    /**
     * Get complete financial summary (cash-basis) for specified month and year.
     *
     * @return array<string, mixed>
     */
    public function getFinancialSummary(?int $month = null, ?int $year = null): array
    {
        $selectedYear = $year ?? (int) Carbon::now()->format('Y');
        $selectedMonth = $month ?? (int) Carbon::now()->format('m');

        // Query successful payments in target period (Cash-In)
        $paymentsQuery = Payment::with(['invoice.tenancy.room', 'invoice.items'])
            ->where('status', 'success')
            ->whereYear('created_at', $selectedYear);

        if ($selectedMonth > 0) {
            $paymentsQuery->whereMonth('created_at', $selectedMonth);
        }

        $payments = $paymentsQuery->latest()->get();

        $totalIncome = (float) $payments->sum('amount');

        // Category breakdown calculation
        $breakdown = [
            'sewa' => 0.0,
            'deposit' => 0.0,
            'listrik' => 0.0,
            'air' => 0.0,
            'denda' => 0.0,
            'lain_lain' => 0.0,
        ];

        foreach ($payments as $payment) {
            $invoice = $payment->invoice;
            $paidAmount = (float) $payment->amount;

            if ($invoice && $invoice->items && $invoice->items->isNotEmpty() && (float) $invoice->total_amount > 0) {
                $invoiceTotal = (float) $invoice->total_amount;
                foreach ($invoice->items as $item) {
                    $itemRatio = (float) $item->amount / $invoiceTotal;
                    $itemShare = $paidAmount * $itemRatio;
                    $type = $item->item_type ?? 'sewa';
                    if (isset($breakdown[$type])) {
                        $breakdown[$type] += $itemShare;
                    } else {
                        $breakdown['lain_lain'] += $itemShare;
                    }
                }
            } else {
                $breakdown['sewa'] += $paidAmount;
            }
        }

        $categoryData = [];
        $labels = [
            'sewa' => 'Sewa Kamar',
            'deposit' => 'Uang Jaminan (Deposit)',
            'listrik' => 'Biaya Listrik',
            'air' => 'Biaya Air Bersih',
            'denda' => 'Denda Keterlambatan',
            'lain_lain' => 'Lain-lain',
        ];

        foreach ($breakdown as $key => $amount) {
            $percentage = $totalIncome > 0 ? round(($amount / $totalIncome) * 100, 1) : 0;
            $categoryData[] = [
                'type' => $key,
                'name' => $labels[$key] ?? ucfirst($key),
                'amount' => round($amount, 2),
                'percentage' => $percentage,
            ];
        }

        // Pending receivables (Invoices not yet paid)
        $pendingReceivables = (float) Invoice::whereIn('status', ['belum_dibayar', 'sebagian_dibayar', 'terlambat'])
            ->with(['payments'])
            ->get()
            ->sum(function (Invoice $inv) {
                if ((float) $inv->paid_amount > 0) {
                    return (float) $inv->remaining_amount;
                }
                $paidFromPayments = (float) $inv->payments->where('status', 'success')->sum('amount');
                $remaining = (float) $inv->total_amount - $paidFromPayments;
                return max(0.0, $remaining);
            });

        // Occupancy calculation
        $totalRooms = Room::where('status', '!=', 'maintenance')->count();
        $occupiedRooms = Room::where('status', 'terisi')->count();
        $occupancyRate = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100, 1) : 0.0;

        // Cash flow trend for the last 6 months
        $trend = $this->calculateSixMonthTrend($selectedYear, $selectedMonth);

        $periodLabel = $selectedMonth > 0
            ? Carbon::createFromDate($selectedYear, $selectedMonth, 1)->translatedFormat('F Y')
            : "Tahun {$selectedYear}";

        return [
            'period' => [
                'month' => $selectedMonth,
                'year' => $selectedYear,
                'label' => $periodLabel,
            ],
            'metrics' => [
                'total_income' => $totalIncome,
                'pending_receivables' => $pendingReceivables,
                'occupancy_rate' => $occupancyRate,
                'total_rooms' => $totalRooms,
                'occupied_rooms' => $occupiedRooms,
            ],
            'category_breakdown' => $categoryData,
            'cashflow_trend' => $trend,
            'transactions_count' => $payments->count(),
        ];
    }

    /**
     * Export report to PDF.
     */
    public function exportPdf(?int $month = null, ?int $year = null): \Barryvdh\DomPDF\PDF
    {
        $summary = $this->getFinancialSummary($month, $year);

        $payments = Payment::with(['invoice.tenancy.room'])
            ->where('status', 'success')
            ->whereYear('created_at', $summary['period']['year']);

        if ($summary['period']['month'] > 0) {
            $payments->whereMonth('created_at', $summary['period']['month']);
        }

        $transactions = $payments->latest()->get();

        return Pdf::loadView('pdf.financial_report', [
            'period_label' => $summary['period']['label'],
            'total_income' => $summary['metrics']['total_income'],
            'pending_receivables' => $summary['metrics']['pending_receivables'],
            'occupancy_rate' => $summary['metrics']['occupancy_rate'],
            'category_breakdown' => $summary['category_breakdown'],
            'transactions' => $transactions,
            'printed_at' => Carbon::now()->translatedFormat('d F Y H:i'),
        ])->setPaper('a4', 'portrait');
    }

    /**
     * Export report to Excel.
     */
    public function exportExcel(?int $month = null, ?int $year = null): BinaryFileResponse
    {
        $summary = $this->getFinancialSummary($month, $year);

        $payments = Payment::with(['invoice.tenancy.room'])
            ->where('status', 'success')
            ->whereYear('created_at', $summary['period']['year']);

        if ($summary['period']['month'] > 0) {
            $payments->whereMonth('created_at', $summary['period']['month']);
        }

        $transactions = $payments->latest()->get();

        $rows = $transactions->map(function ($trx) {
            return [
                'created_at' => $trx->created_at,
                'invoice_number' => $trx->invoice->invoice_number ?? '-',
                'tenant_name' => $trx->invoice->tenancy->tenant_name ?? '-',
                'room_number' => $trx->invoice->tenancy->room->room_number ?? '-',
                'method' => $trx->method === 'gateway' ? 'Gateway (' . strtoupper($trx->gateway_provider ?? 'Auto') . ')' : 'Transfer Manual',
                'status' => $trx->status,
                'amount' => $trx->amount,
            ];
        });

        $fileName = 'laporan-keuangan-' . StrSlug($summary['period']['label']) . '.xlsx';

        return Excel::download(new FinancialReportExport(collect($rows)), $fileName);
    }

    /**
     * Calculate 6-month historical cash flow trend.
     *
     * @return array<int, array<string, mixed>>
     */
    private function calculateSixMonthTrend(int $endYear, int $endMonth): array
    {
        $trend = [];
        $baseDate = Carbon::createFromDate($endYear, $endMonth, 1)->startOfMonth();

        for ($i = 5; $i >= 0; $i--) {
            $target = (clone $baseDate)->subMonths($i);
            $y = (int) $target->format('Y');
            $m = (int) $target->format('m');

            $income = (float) Payment::where('status', 'success')
                ->whereYear('created_at', $y)
                ->whereMonth('created_at', $m)
                ->sum('amount');

            $invoicesIssued = (float) Invoice::whereYear('issue_date', $y)
                ->whereMonth('issue_date', $m)
                ->sum('total_amount');

            $trend[] = [
                'month' => $target->translatedFormat('M Y'),
                'short_month' => $target->translatedFormat('M'),
                'income' => $income,
                'invoiced' => $invoicesIssued,
            ];
        }

        return $trend;
    }
}

function StrSlug(string $title): string
{
    return strtolower(trim((string) preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));
}
