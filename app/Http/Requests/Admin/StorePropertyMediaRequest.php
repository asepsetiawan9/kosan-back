<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StorePropertyMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:51200', // 50MB max for video, service handles 5MB check for images
                'mimes:jpg,jpeg,png,webp,mp4,webm,mov',
            ],
            'thumbnail' => [
                'nullable',
                'file',
                'max:5120',
                'mimes:jpg,jpeg,png,webp',
            ],
            'media_type' => ['nullable', 'in:image,video'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_featured' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'File media wajib diunggah.',
            'file.max' => 'Ukuran file media maksimal 50MB.',
            'file.mimes' => 'Format file harus berupa gambar (JPG, PNG, WebP) atau video (MP4, WebM, MOV).',
            'thumbnail.max' => 'Ukuran file thumbnail maksimal 5MB.',
            'thumbnail.mimes' => 'Thumbnail harus berformat gambar (JPG, PNG, WebP).',
        ];
    }
}
