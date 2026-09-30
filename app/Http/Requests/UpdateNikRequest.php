<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNikRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'nik' => [
                'required',
                'string',
                'digits:16',
                Rule::unique('users', 'nik')->ignore($userId),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'nik.required' => 'Nomor Induk Kependudukan (NIK) wajib diisi.',
            'nik.digits' => 'NIK harus tepat terdiri dari 16 digit angka.',
            'nik.unique' => 'NIK ini telah terdaftar pada akun lain.',
        ];
    }
}
