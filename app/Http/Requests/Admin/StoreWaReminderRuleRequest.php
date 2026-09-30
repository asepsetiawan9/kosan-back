<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreWaReminderRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'trigger_type' => ['required', 'in:before_due,on_due,after_due'],
            'offset_days' => ['required', 'integer', 'min:0', 'max:365'],
            'send_time' => ['required', 'date_format:H:i,H:i:s'],
            'template_key' => ['required', 'string', 'exists:wa_templates,key'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama aturan pengingat wajib diisi.',
            'trigger_type.required' => 'Tipe pemicu waktu jatuh tempo wajib dipilih.',
            'trigger_type.in' => 'Tipe pemicu harus salah satu dari: before_due, on_due, atau after_due.',
            'offset_days.required' => 'Jumlah offset hari wajib diisi.',
            'send_time.required' => 'Waktu kirim harian (jam) wajib diisi.',
            'template_key.required' => 'Kunci template pesan wajib dipilih.',
            'template_key.exists' => 'Template yang dipilih tidak ditemukan dalam sistem.',
        ];
    }
}
