<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManualPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1000'],
            'proof_file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'], // 5MB limit
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'Nominal pembayaran wajib diisi.',
            'amount.numeric' => 'Nominal pembayaran harus berupa angka.',
            'amount.min' => 'Nominal pembayaran minimal Rp 1.000.',
            'proof_file.required' => 'Bukti transfer wajib diunggah.',
            'proof_file.file' => 'Bukti transfer harus berupa berkas yang valid.',
            'proof_file.mimes' => 'Format bukti transfer harus berupa JPG, PNG, WEBP, atau PDF.',
            'proof_file.max' => 'Ukuran berkas bukti transfer maksimal 5MB.',
        ];
    }
}
