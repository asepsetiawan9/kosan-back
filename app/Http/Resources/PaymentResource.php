<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Payment;
use App\Services\ManualPaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $signedProofUrl = null;
        if ($this->proof_file) {
            $manualService = app(ManualPaymentService::class);
            $signedProofUrl = $manualService->generateSignedProofUrl($this->resource);
        }

        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'amount' => (float) $this->amount,
            'method' => $this->method,
            'gateway_provider' => $this->gateway_provider,
            'gateway_transaction_id' => $this->gateway_transaction_id,
            'proof_url' => $signedProofUrl,
            'status' => $this->status,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'verified_by' => $this->verifier ? [
                'id' => $this->verifier->id,
                'name' => $this->verifier->name,
                'email' => $this->verifier->email,
            ] : null,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'invoice' => $this->whenLoaded('invoice', function () {
                return [
                    'id' => $this->invoice->id,
                    'invoice_number' => $this->invoice->invoice_number,
                    'period' => $this->invoice->period,
                    'total_amount' => (float) $this->invoice->total_amount,
                    'paid_amount' => (float) $this->invoice->paid_amount,
                    'status' => $this->invoice->status,
                    'due_date' => $this->invoice->due_date?->toDateString(),
                    'tenancy' => $this->invoice->tenancy ? [
                        'id' => $this->invoice->tenancy->id,
                        'tenant_name' => $this->invoice->tenancy->tenant_name,
                        'tenant_phone' => $this->invoice->tenancy->tenant_phone,
                        'tenant_email' => $this->invoice->tenancy->tenant_email,
                        'room' => $this->invoice->tenancy->room ? [
                            'id' => $this->invoice->tenancy->room->id,
                            'room_number' => $this->invoice->tenancy->room->room_number,
                            'name' => $this->invoice->tenancy->room->name,
                            'type' => $this->invoice->tenancy->room->type,
                        ] : null,
                    ] : null,
                ];
            }),
        ];
    }
}
