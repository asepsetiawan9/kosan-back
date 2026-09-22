<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reportService
    ) {}

    /**
     * Get financial income summary, category distribution, and cash flow trend.
     */
    public function income(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2020,2035'],
        ]);

        $month = isset($validated['month']) ? (int) $validated['month'] : null;
        $year = isset($validated['year']) ? (int) $validated['year'] : null;

        $summary = $this->reportService->getFinancialSummary($month, $year);

        return response()->json([
            'data' => $summary,
        ]);
    }

    /**
     * Export financial report to PDF or Excel.
     */
    public function export(Request $request): Response|BinaryFileResponse
    {
        $validated = $request->validate([
            'format' => ['required', 'string', 'in:pdf,excel,xlsx'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2020,2035'],
        ]);

        $month = isset($validated['month']) ? (int) $validated['month'] : null;
        $year = isset($validated['year']) ? (int) $validated['year'] : null;
        $format = $validated['format'];

        if ($format === 'excel' || $format === 'xlsx') {
            return $this->reportService->exportExcel($month, $year);
        }

        $pdf = $this->reportService->exportPdf($month, $year);
        $periodSlug = ($month ? "{$month}-" : '') . ($year ?? date('Y'));
        $filename = "laporan-keuangan-kos-{$periodSlug}.pdf";

        return $pdf->download($filename);
    }
}
