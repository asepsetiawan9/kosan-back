<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTenancyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_id' => ['required', 'uuid', 'exists:rooms,id'],
            'tenant_name' => ['required', 'string', 'max:255'],
            'tenant_phone' => ['required', 'string', 'max:20'],
            'tenant_email' => ['nullable', 'email', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'billing_due_day' => ['required', 'integer', 'min:1', 'max:31'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'create_first_invoice' => ['nullable', 'boolean'],
        ];
    }
}
