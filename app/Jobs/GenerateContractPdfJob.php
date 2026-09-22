<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenancy;
use App\Services\ContractPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateContractPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Tenancy $tenancy
    ) {}

    /**
     * Execute the job to generate draft contract in background.
     */
    public function handle(ContractPdfService $pdfService): void
    {
        Log::info('[GENERATE CONTRACT PDF JOB STARTED]', [
            'tenancy_id' => $this->tenancy->id,
            'tenant_name' => $this->tenancy->tenant_name,
        ]);

        $contract = $pdfService->generateDraft($this->tenancy);

        Log::info('[GENERATE CONTRACT PDF JOB FINISHED]', [
            'tenancy_id' => $this->tenancy->id,
            'contract_id' => $contract->id,
            'contract_number' => $contract->contract_number,
        ]);
    }
}
