<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class StorePublicComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reporter_name' => ['required', 'string', 'min:3', 'max:100'],
            'reporter_phone' => ['required', 'string', 'min:10', 'max:25'],
            'property_id' => ['nullable', 'uuid', 'exists:properties,id'],
            'room_number' => ['nullable', 'string', 'max:50'],
            'category' => ['required', 'in:fasilitas_rusak,kebersihan,keamanan,air_listrik,lainnya'],
            'description' => ['required', 'string', 'min:15', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:3'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ];
    }

    public function messages(): array
    {
        return [
            'reporter_name.required' => 'Nama pelapor wajib diisi.',
            'reporter_name.min' => 'Nama pelapor minimal 3 karakter.',
            'reporter_phone.required' => 'Nomor WhatsApp / telepon wajib diisi.',
            'reporter_phone.min' => 'Nomor telepon minimal 10 digit.',
            'property_id.exists' => 'Properti yang dipilih tidak valid.',
            'category.required' => 'Kategori aduan wajib dipilih.',
            'category.in' => 'Kategori aduan yang dipilih tidak valid.',
            'description.required' => 'Deskripsi keluhan wajib diisi.',
            'description.min' => 'Deskripsi keluhan minimal 15 karakter agar tim kami dapat memahami kendala.',
            'photos.max' => 'Maksimal 3 foto yang dapat dilampirkan.',
            'photos.*.mimes' => 'Format foto harus berupa JPG, PNG, atau WebP.',
            'photos.*.max' => 'Ukuran setiap foto maksimal 3 MB.',
        ];
    }
}
