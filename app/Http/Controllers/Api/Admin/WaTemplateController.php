<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PreviewWaTemplateRequest;
use App\Http\Requests\Admin\UpdateWaTemplateRequest;
use App\Http\Resources\WaTemplateResource;
use App\Repositories\Contracts\WaTemplateRepositoryInterface;
use App\Services\WaTemplateRenderer;
use Illuminate\Http\JsonResponse;

class WaTemplateController extends Controller
{
    public function __construct(
        protected WaTemplateRepositoryInterface $templateRepo,
        protected WaTemplateRenderer $renderer
    ) {}

    /**
     * List all templates with available placeholders.
     */
    public function index(): JsonResponse
    {
        $templates = $this->templateRepo->getAll();

        return response()->json([
            'data' => WaTemplateResource::collection($templates),
            'placeholders' => $this->renderer->getSupportedPlaceholders(),
        ], 200);
    }

    /**
     * Show a single template.
     */
    public function show(string $id): JsonResponse
    {
        $template = $this->templateRepo->findById($id);

        if (!$template) {
            return response()->json(['message' => 'Template pesan tidak ditemukan.'], 404);
        }

        return response()->json([
            'data' => new WaTemplateResource($template),
            'placeholders' => $this->renderer->getSupportedPlaceholders(),
        ], 200);
    }

    /**
     * Update template content.
     */
    public function update(UpdateWaTemplateRequest $request, string $id): JsonResponse
    {
        $template = $this->templateRepo->findById($id);

        if (!$template) {
            return response()->json(['message' => 'Template pesan tidak ditemukan.'], 404);
        }

        $updated = $this->templateRepo->update($template, $request->validated());

        return response()->json([
            'message' => 'Template pesan WhatsApp berhasil diperbarui.',
            'data' => new WaTemplateResource($updated),
        ], 200);
    }

    /**
     * Preview template rendered with placeholder data.
     */
    public function preview(PreviewWaTemplateRequest $request): JsonResponse
    {
        $sampleParams = array_merge([
            'nama' => 'Budi Santoso',
            'kamar' => '102 (Deluxe)',
            'periode' => 'Oktober 2026',
            'nominal' => 'Rp 1.500.000',
            'jatuh_tempo' => '05/10/2026',
            'sisa_hari' => '1',
            'nama_kos' => 'Kos Melati Residence',
            'no_rekening' => 'BCA 1234567890 a/n Bapak Haji Asep',
            'alasan_penolakan' => 'Foto bukti transfer buram dan tidak terbaca jelas nominalnya.',
            'daftar_tagihan' => "1. Tagihan September 2026 (Rp 1.500.000)\n2. Tagihan Oktober 2026 (Rp 1.500.000)",
        ], (array) $request->input('params', []));

        $body = (string) ($request->input('body') ?? '');
        $templateKey = $request->input('template_key');

        $rendered = !empty($body)
            ? $this->renderer->render($body, $sampleParams)
            : $this->renderer->render((string) $templateKey, $sampleParams);

        return response()->json([
            'rendered' => $rendered,
            'sample_params' => $sampleParams,
        ], 200);
    }
}
