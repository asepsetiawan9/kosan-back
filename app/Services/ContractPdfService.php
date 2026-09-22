<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Contract;
use App\Models\Tenancy;
use App\Repositories\Contracts\ContractRepositoryInterface;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class ContractPdfService
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository
    ) {}

    /**
     * Generate an unsigned draft contract PDF for a tenancy.
     */
    public function generateDraft(Tenancy $tenancy): Contract
    {
        $tenancy->loadMissing(['room.facilities', 'user']);

        // Check if there is an existing draft
        $existingContract = $this->contractRepository->findLatestByTenancyId($tenancy->id);

        if ($existingContract && $existingContract->isSigned()) {
            throw new DomainException('Sewa ini sudah memiliki kontrak sah yang ditandatangani.');
        }

        $contractNumber = $existingContract?->contract_number ?? $this->generateContractNumber($tenancy);
        $contractId = $existingContract?->id ?? (string) Str::uuid();

        $draftRelPath = "private/contracts/drafts/{$contractId}_draft.pdf";

        $pdf = Pdf::loadView('pdf.contract', [
            'contract' => (object) [
                'id' => $contractId,
                'contract_number' => $contractNumber,
                'isSigned' => fn() => false,
                'signed_at' => null,
            ],
            'tenancy' => $tenancy,
            'generated_at' => Carbon::now()->translatedFormat('d F Y'),
            'signature_base64' => null,
            'is_signed' => false,
        ])->setPaper('a4', 'portrait');

        $output = $pdf->output();
        Storage::disk('local')->put($draftRelPath, $output);

        if ($existingContract) {
            $this->contractRepository->update($existingContract, [
                'contract_number' => $contractNumber,
                'file_path' => $draftRelPath,
                'status' => 'dikirim',
            ]);
            return $existingContract->fresh(['tenancy.room', 'tenancy.user']);
        }

        return $this->contractRepository->create([
            'id' => $contractId,
            'tenancy_id' => $tenancy->id,
            'contract_number' => $contractNumber,
            'file_path' => $draftRelPath,
            'status' => 'dikirim',
        ]);
    }

    /**
     * Apply digital signature, finalize PDF, and freeze contract as immutable.
     */
    public function applySignature(Contract $contract, string $signatureBase64): Contract
    {
        if ($contract->isSigned()) {
            throw new DomainException('Kontrak sewa ini telah ditandatangani dan terkunci permanen (immutable).');
        }

        $tenancy = $contract->tenancy;
        $tenancy->loadMissing(['room.facilities', 'user']);

        // Ensure data URL format or plain base64
        $cleanBase64 = preg_replace('/^data:image\/\w+;base64,/', '', $signatureBase64);
        $decodedPng = base64_decode($cleanBase64);

        if (!$decodedPng) {
            throw new DomainException('Format tanda tangan digital tidak valid.');
        }

        $sigRelPath = "private/contracts/signatures/{$contract->id}_sig.png";
        Storage::disk('local')->put($sigRelPath, $decodedPng);

        $dataUri = 'data:image/png;base64,' . base64_encode($decodedPng);

        $signedAt = Carbon::now();
        $signedRelPath = "private/contracts/signed/{$contract->id}_signed.pdf";

        $mockContract = (object) [
            'id' => $contract->id,
            'contract_number' => $contract->contract_number,
            'isSigned' => fn() => true,
            'signed_at' => $signedAt,
        ];

        $pdf = Pdf::loadView('pdf.contract', [
            'contract' => $mockContract,
            'tenancy' => $tenancy,
            'generated_at' => Carbon::parse($contract->created_at)->translatedFormat('d F Y'),
            'signature_base64' => $dataUri,
            'is_signed' => true,
        ])->setPaper('a4', 'portrait');

        Storage::disk('local')->put($signedRelPath, $pdf->output());

        $this->contractRepository->update($contract, [
            'signature_image' => $sigRelPath,
            'signed_file_path' => $signedRelPath,
            'signed_at' => $signedAt,
            'status' => 'ditandatangani',
        ]);

        $freshContract = $contract->fresh(['tenancy.room', 'tenancy.user']);

        // Send WhatsApp notification
        if (!empty($tenancy->tenant_phone)) {
            $msg = "Halo *{$tenancy->tenant_name}*, Kontrak sewa Anda (*{$freshContract->contract_number}*) untuk Kamar {$tenancy->room->room_number} telah berhasil ditandatangani secara sah. Anda dapat mengunduh salinan resmi pada portal penghuni.";
            SendWhatsAppNotificationJob::dispatch($tenancy->tenant_phone, $msg);
        }

        return $freshContract;
    }

    /**
     * Generate temporary signed streaming URL for the contract PDF.
     */
    public function generateSignedStreamUrl(Contract $contract, string $route = 'admin.contracts.stream'): string
    {
        return URL::temporarySignedRoute(
            $route,
            now()->addMinutes(15),
            ['id' => $contract->id]
        );
    }

    /**
     * Get absolute path of active PDF file (signed if available, otherwise draft).
     */
    public function getActivePdfPath(Contract $contract): string
    {
        $relPath = $contract->isSigned() && $contract->signed_file_path
            ? $contract->signed_file_path
            : $contract->file_path;

        return Storage::disk('local')->path($relPath);
    }

    private function generateContractNumber(Tenancy $tenancy): string
    {
        $prefix = 'KTR';
        $period = date('Ym');
        $shortId = strtoupper(substr(str_replace('-', '', $tenancy->id), 0, 4));
        $random = rand(100, 999);

        return "{$prefix}/{$period}/{$shortId}{$random}";
    }
}
