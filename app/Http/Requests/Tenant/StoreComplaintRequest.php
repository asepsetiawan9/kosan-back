<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class StoreComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->isTenant();
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'in:fasilitas_rusak,kebersihan,keamanan,lainnya'],
            'description' => ['required', 'string', 'min:10'],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpeg,png,jpg,webp', 'max:3072'],
        ];
    }

    public function messages(): array
    {
        return [
            'category.required' => 'Kategori aduan wajib dipilih.',
            'category.in' => 'Kategori aduan yang dipilih tidak valid.',
            'description.required' => 'Deskripsi keluhan wajib diisi.',
            'description.min' => 'Deskripsi keluhan minimal 10 karakter agar admin dapat memahami masalah.',
            'photo.image' => 'File lampiran harus berupa gambar.',
            'photo.max' => 'Ukuran foto maksimal 3MB.',
        ];
    }
}
