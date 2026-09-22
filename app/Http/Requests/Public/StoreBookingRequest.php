<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_id' => ['required', 'uuid', 'exists:rooms,id'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'requested_move_in' => ['required', 'date', 'after_or_equal:today'],
            'ktp_file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:3072'], // Max 3MB
        ];
    }

    public function messages(): array
    {
        return [
            'room_id.required' => 'Kamar wajib dipilih.',
            'room_id.exists' => 'Kamar yang dipilih tidak valid.',
            'name.required' => 'Nama lengkap wajib diisi.',
            'phone.required' => 'Nomor WhatsApp / telepon wajib diisi.',
            'requested_move_in.required' => 'Tanggal rencana masuk wajib dipilih.',
            'requested_move_in.after_or_equal' => 'Tanggal rencana masuk minimal hari ini.',
            'ktp_file.required' => 'Foto atau scan KTP wajib diunggah.',
            'ktp_file.mimes' => 'Format file KTP harus berupa JPG, PNG, atau PDF.',
            'ktp_file.max' => 'Ukuran file KTP maksimal 3 MB.',
        ];
    }
}
