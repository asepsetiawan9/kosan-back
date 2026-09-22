<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class SignContractRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'signature' => ['required', 'string', 'min:100'],
            'agree_terms' => ['required', 'accepted'],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'signature.required' => 'Bubuhkan tanda tangan digital Anda pada kanvas.',
            'signature.min' => 'Goresan tanda tangan digital belum lengkap atau tidak valid.',
            'agree_terms.required' => 'Anda wajib menyetujui syarat dan ketentuan kontrak hukum.',
            'agree_terms.accepted' => 'Anda wajib mencentang persetujuan keabsahan tanda tangan sebelum mengirimkan.',
        ];
    }
}
