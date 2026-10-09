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
use Illuminate\Validation\Rule;

class BillingTemplateController extends Controller
{
    public function __construct(
        private readonly BillingService $billingService
    ) {}

    public function index(): JsonResponse
    {
        $templates = BillingTemplate::query()
            ->orderBy('title', 'asc')
            ->get();

        return response()->json([
            'data' => $templates,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:60', 'alpha_dash', 'unique:billing_templates,key'],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $template = BillingTemplate::create($validated);

        return response()->json([
            'message' => 'Template tagihan berhasil dibuat.',
            'data' => $template,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $template = BillingTemplate::findOrFail($id);

        $validated = $request->validate([
            'key' => ['sometimes', 'required', 'string', 'max:60', 'alpha_dash', Rule::unique('billing_templates', 'key')->ignore($template->id)],
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'body' => ['sometimes', 'required', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $template->update($validated);

        return response()->json([
            'message' => 'Template tagihan berhasil diperbarui.',
            'data' => $template,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $template = BillingTemplate::findOrFail($id);
        $template->delete();

        return response()->json([
            'message' => 'Template tagihan berhasil dihapus.',
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template_id' => ['nullable', 'string', 'exists:billing_templates,id'],
            'body' => ['nullable', 'string'],
            'tenancy_id' => ['nullable', 'string', 'exists:tenancies,id'],
        ]);

        $body = $validated['body'] ?? null;
        if (!$body && !empty($validated['template_id'])) {
            $template = BillingTemplate::findOrFail($validated['template_id']);
            $body = $template->body;
        }

        if (!$body) {
            return response()->json(['message' => 'Konten template atau template_id wajib diisi.'], 422);
        }

        $dummyTemplate = new BillingTemplate([
            'key' => 'preview',
            'title' => 'Preview',
            'body' => $body,
        ]);

        $phone = '081234567890';
        $tenancy = null;
        $invoice = null;

        if (!empty($validated['tenancy_id'])) {
            $tenancy = Tenancy::with(['room.property', 'user', 'invoices' => fn($q) => $q->latest()])->find($validated['tenancy_id']);
            if ($tenancy) {
                $phone = $tenancy->tenant_phone ?: ($tenancy->user?->phone ?? $phone);
                $invoice = $tenancy->invoices->first();
            }
        }

        if (!$tenancy) {
            // Mock tenancy for sample preview
            $tenancy = new Tenancy([
                'tenant_name' => 'Budi Santoso',
                'tenant_phone' => $phone,
                'billing_due_day' => 15,
            ]);
            $tenancy->setRelation('room', new \App\Models\Room([
                'room_number' => '102',
                'name' => 'Kamar 102 (Deluxe)',
                'price' => 1500000,
            ]));
            $tenancy->room->setRelation('property', new \App\Models\Property([
                'name' => 'Kost Griya Asri',
            ]));
            $invoice = new Invoice([
                'period' => date('F Y'),
                'total_amount' => 1500000,
                'paid_amount' => 0,
                'due_date' => date('Y-m-15'),
            ]);
        }

        $rendered = $this->billingService->renderTemplate($dummyTemplate, $tenancy, $invoice);
        $waLink = $this->billingService->generateWhatsAppLink($phone, $rendered);

        return response()->json([
            'data' => [
                'rendered_msg' => $rendered,
                'phone' => $phone,
                'wa_link' => $waLink,
            ],
        ]);
    }
}
