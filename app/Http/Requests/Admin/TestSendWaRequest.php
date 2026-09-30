<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class TestSendWaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string'],
            'message' => ['required_without:template_key', 'nullable', 'string', 'max:1500'],
            'template_key' => ['nullable', 'string', 'exists:wa_templates,key'],
            'template_params' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'Nomor WhatsApp tujuan wajib diisi.',
            'message.required_without' => 'Pesan teks wajib diisi jika tidak memilih template.',
            'template_key.exists' => 'Template yang dipilih tidak ditemukan dalam sistem.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $phone = (string) $this->input('phone', '');
            if (!PhoneNumber::isValid($phone)) {
                $v->errors()->add('phone', 'Format nomor WhatsApp tidak valid. Gunakan format nomor ponsel Indonesia (contoh: 08123456789 atau +628123456789).');
            }
        });
    }
}
