<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingTemplate;
use App\Models\Invoice;
use App\Models\Tenancy;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(
        private readonly BillingService $billingService
    ) {}

    public function summary(): JsonResponse
    {
        $summary = $this->billingService->getSummary();

        return response()->json([
            'data' => $summary,
        ]);
    }

    public function targets(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'search', 'property_id']);
        $targets = $this->billingService->getTargets($filters);

        return response()->json([
            'data' => $targets,
        ]);
    }

    public function generateLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenancy_id' => ['required', 'string', 'exists:tenancies,id'],
            'template_id' => ['nullable', 'string', 'exists:billing_templates,id'],
            'template_key' => ['nullable', 'string'],
            'custom_message' => ['nullable', 'string'],
            'invoice_id' => ['nullable', 'string', 'exists:invoices,id'],
        ]);

        $tenancy = Tenancy::with(['room.property', 'user', 'invoices'])->findOrFail($validated['tenancy_id']);
        
        $invoice = null;
        if (!empty($validated['invoice_id'])) {
            $invoice = Invoice::find($validated['invoice_id']);
        } else {
            $invoice = $tenancy->invoices()
                ->whereIn('status', ['belum_bayar', 'sebagian_dibayar', 'lewat_jatuh_tempo', 'menunggu_verifikasi'])
                ->orderBy('due_date', 'asc')
                ->first();
        }

        $message = $validated['custom_message'] ?? null;

        if (!$message) {
            $template = null;
            if (!empty($validated['template_id'])) {
                $template = BillingTemplate::find($validated['template_id']);
            } elseif (!empty($validated['template_key'])) {
                $template = BillingTemplate::where('key', $validated['template_key'])->first();
            } else {
                $template = BillingTemplate::where('key', 'tagihan_bulanan')->first()
                    ?? BillingTemplate::first();
            }

            if (!$template) {
                return response()->json(['message' => 'Template tagihan tidak ditemukan.'], 404);
            }

            $message = $this->billingService->renderTemplate($template, $tenancy, $invoice);
        }

        $phone = $tenancy->tenant_phone ?: ($tenancy->user?->phone ?? '');
        $waLink = $this->billingService->generateWhatsAppLink($phone, $message);

        return response()->json([
            'data' => [
                'tenancy_id' => $tenancy->id,
                'tenant_name' => $tenancy->tenant_name ?: ($tenancy->user?->name ?? 'Penghuni'),
                'phone' => $phone,
                'rendered_msg' => $message,
                'wa_link' => $waLink,
                'invoice_id' => $invoice?->id,
            ],
        ]);
    }

    public function bulkGenerateLinks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenancy_ids' => ['required', 'array', 'min:1'],
            'tenancy_ids.*' => ['required', 'string', 'exists:tenancies,id'],
            'template_id' => ['nullable', 'string', 'exists:billing_templates,id'],
            'template_key' => ['nullable', 'string'],
        ]);

        $template = null;
        if (!empty($validated['template_id'])) {
            $template = BillingTemplate::find($validated['template_id']);
        } elseif (!empty($validated['template_key'])) {
            $template = BillingTemplate::where('key', $validated['template_key'])->first();
        } else {
            $template = BillingTemplate::where('key', 'tagihan_bulanan')->first()
                ?? BillingTemplate::first();
        }

        if (!$template) {
            return response()->json(['message' => 'Template tagihan tidak ditemukan.'], 404);
        }

        $tenancies = Tenancy::with(['room.property', 'user', 'invoices'])
            ->whereIn('id', $validated['tenancy_ids'])
            ->get();

        $results = [];

        foreach ($tenancies as $tenancy) {
            $invoice = $tenancy->invoices
                ->whereIn('status', ['belum_bayar', 'sebagian_dibayar', 'lewat_jatuh_tempo', 'menunggu_verifikasi'])
                ->sortBy('due_date')
                ->first();

            $phone = $tenancy->tenant_phone ?: ($tenancy->user?->phone ?? '');
            $message = $this->billingService->renderTemplate($template, $tenancy, $invoice);
            $waLink = $this->billingService->generateWhatsAppLink($phone, $message);

            $results[] = [
                'tenancy_id' => $tenancy->id,
                'tenant_name' => $tenancy->tenant_name ?: ($tenancy->user?->name ?? 'Penghuni'),
                'room_number' => $tenancy->room?->room_number ?? '-',
                'phone' => $phone,
                'invoice_id' => $invoice?->id,
                'rendered_msg' => $message,
                'wa_link' => $waLink,
            ];
        }

        return response()->json([
            'data' => $results,
        ]);
    }

    public function log(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenancy_id' => ['required', 'string', 'exists:tenancies,id'],
            'invoice_id' => ['nullable', 'string', 'exists:invoices,id'],
            'template_id' => ['nullable', 'string', 'exists:billing_templates,id'],
            'rendered_msg' => ['required', 'string'],
            'phone_target' => ['required', 'string'],
            'channel' => ['nullable', 'string', 'in:wa_web,sms,manual'],
        ]);

        $adminId = (string) $request->user()->id;
        $log = $this->billingService->logBilling($validated, $adminId);

        return response()->json([
            'message' => 'Riwayat penagihan berhasil dicatat.',
            'data' => $log,
        ], 201);
    }

    public function history(Request $request): JsonResponse
    {
        $filters = $request->only(['tenancy_id', 'template_id', 'channel']);
        $perPage = (int) $request->get('per_page', 15);
        $history = $this->billingService->getHistory($filters, $perPage);

        return response()->json($history);
    }
}
