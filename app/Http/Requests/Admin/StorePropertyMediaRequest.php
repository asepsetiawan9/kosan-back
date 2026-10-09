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
        $mediaType = $this->input('media_type', 'image');

        if ($mediaType === 'video') {
            return [
                'media_type' => ['required', 'in:video'],
                'youtube_url' => [
                    'required',
                    'string',
                    'regex:/^(https?:\/\/)?(www\.)?(youtube\.com|youtu\.be)\/.+$/i',
                ],
                'file' => ['prohibited'], // Dilarang mengunggah berkas video mentah
                'thumbnail' => [
                    'nullable',
                    'file',
                    'max:5120',
                    'mimes:jpg,jpeg,png,webp',
                ],
                'title' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:1000'],
                'is_featured' => ['nullable', 'boolean'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
            ];
        }

        return [
            'media_type' => ['nullable', 'in:image'],
            'file' => [
                'required',
                'file',
                'max:5120', // Foto maksimal 5MB
                'mimes:jpg,jpeg,png,webp',
            ],
            'thumbnail' => [
                'nullable',
                'file',
                'max:5120',
                'mimes:jpg,jpeg,png,webp',
            ],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_featured' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Berkas foto properti wajib diunggah.',
            'file.max' => 'Ukuran file foto maksimal 5MB.',
            'file.mimes' => 'Format file foto harus berupa gambar JPG, PNG, atau WebP.',
            'file.prohibited' => 'Unggah berkas video langsung dinonaktifkan. Silakan gunakan link video dari YouTube.',
            'youtube_url.required' => 'Link / tautan video YouTube wajib diisi.',
            'youtube_url.regex' => 'Format tautan harus berupa link YouTube yang valid (youtube.com atau youtu.be).',
            'thumbnail.max' => 'Ukuran file thumbnail kustom maksimal 5MB.',
            'thumbnail.mimes' => 'Thumbnail harus berformat gambar (JPG, PNG, WebP).',
        ];
    }
}
