<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreWaReminderRuleRequest;
use App\Http\Requests\Admin\UpdateWaReminderRuleRequest;
use App\Http\Resources\WaReminderLogResource;
use App\Http\Resources\WaReminderRuleResource;
use App\Repositories\Contracts\WaReminderRuleRepositoryInterface;
use App\Services\WaReminderService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WaReminderRuleController extends Controller
{
    public function __construct(
        protected WaReminderRuleRepositoryInterface $ruleRepo,
        protected WaReminderService $reminderService
    ) {}

    /**
     * List all reminder rules with summary overview.
     */
    public function index(): JsonResponse
    {
        $rules = $this->ruleRepo->getAll();
        $summary = $this->reminderService->getSummary();

        return response()->json([
            'data' => WaReminderRuleResource::collection($rules),
            'summary' => $summary,
        ], 200);
    }

    /**
     * Create a new reminder rule.
     */
    public function store(StoreWaReminderRuleRequest $request): JsonResponse
    {
        $rule = $this->ruleRepo->create($request->validated());

        return response()->json([
            'message' => 'Aturan pengingat tagihan WhatsApp berhasil dibuat.',
            'data' => new WaReminderRuleResource($rule->load('template')),
        ], 201);
    }

    /**
     * Show a single reminder rule.
     */
    public function show(string $id): JsonResponse
    {
        $rule = $this->ruleRepo->findById($id);

        if (!$rule) {
            return response()->json(['message' => 'Aturan pengingat tidak ditemukan.'], 404);
        }

        return response()->json([
            'data' => new WaReminderRuleResource($rule),
        ], 200);
    }

    /**
     * Update an existing reminder rule.
     */
    public function update(UpdateWaReminderRuleRequest $request, string $id): JsonResponse
    {
        $updated = $this->ruleRepo->update($id, $request->validated());

        if (!$updated) {
            return response()->json(['message' => 'Aturan pengingat tidak ditemukan.'], 404);
        }

        return response()->json([
            'message' => 'Aturan pengingat tagihan WhatsApp berhasil diperbarui.',
            'data' => new WaReminderRuleResource($updated),
        ], 200);
    }

    /**
     * Delete a reminder rule.
     */
    public function destroy(string $id): JsonResponse
    {
        $deleted = $this->ruleRepo->delete($id);

        if (!$deleted) {
            return response()->json(['message' => 'Aturan pengingat tidak ditemukan.'], 404);
        }

        return response()->json([
            'message' => 'Aturan pengingat WhatsApp berhasil dihapus.',
        ], 200);
    }

    /**
     * Toggle active status of a reminder rule.
     */
    public function toggle(string $id): JsonResponse
    {
        $updated = $this->ruleRepo->toggleActive($id);

        if (!$updated) {
            return response()->json(['message' => 'Aturan pengingat tidak ditemukan.'], 404);
        }

        $statusText = $updated->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return response()->json([
            'message' => "Aturan pengingat WhatsApp berhasil {$statusText}.",
            'data' => new WaReminderRuleResource($updated),
        ], 200);
    }

    /**
     * Run a simulation (dry-run) of reminder rules for today or a specific date.
     */
    public function dryRun(Request $request): JsonResponse
    {
        $dateStr = $request->query('date') ?? $request->input('date');
        $targetDate = $dateStr ? Carbon::parse((string) $dateStr, 'Asia/Jakarta') : null;

        $result = $this->reminderService->runReminders(true, $targetDate);

        return response()->json([
            'message' => 'Simulasi pengingat WhatsApp berhasil dijalankan.',
            'data' => $result,
        ], 200);
    }

    /**
     * Trigger immediate real execution of reminder rules.
     */
    public function run(Request $request): JsonResponse
    {
        $dateStr = $request->input('date');
        $targetDate = $dateStr ? Carbon::parse((string) $dateStr, 'Asia/Jakarta') : null;

        $result = $this->reminderService->runReminders(false, $targetDate);

        return response()->json([
            'message' => "Pengingat WhatsApp berhasil diproses. {$result['reminders_sent']} pesan dimasukkan ke antrean pengiriman.",
            'data' => $result,
        ], 200);
    }

    /**
     * Get paginated logs of past automated reminders.
     */
    public function logs(Request $request): JsonResponse
    {
        $filters = [
            'rule_id' => $request->query('rule_id'),
            'invoice_id' => $request->query('invoice_id'),
            'date' => $request->query('date'),
            'search' => $request->query('search'),
        ];

        $perPage = (int) $request->query('per_page', 15);
        $paginated = $this->reminderService->getRecentLogs($filters, $perPage);

        return WaReminderLogResource::collection($paginated)->response();
    }
}
