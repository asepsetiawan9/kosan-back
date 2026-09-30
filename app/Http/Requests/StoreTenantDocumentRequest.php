<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTenantDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', 'in:ktp,kk,sim,lainnya'],
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png,pdf', 'max:5120'], // Max 5MB
        ];
    }

    public function messages(): array
    {
        return [
            'document_type.required' => 'Tipe dokumen wajib dipilih.',
            'document_type.in' => 'Tipe dokumen harus salah satu dari: KTP, KK, SIM, atau Lainnya.',
            'file.required' => 'Berkas dokumen wajib diunggah.',
            'file.mimes' => 'Format berkas harus berupa JPG, PNG, atau PDF.',
            'file.max' => 'Ukuran berkas maksimal 5MB.',
        ];
    }
}
