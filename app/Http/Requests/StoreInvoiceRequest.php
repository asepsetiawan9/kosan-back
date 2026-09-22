<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenancy_id' => ['required', 'uuid', 'exists:tenancies,id'],
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'due_date' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.amount' => ['required', 'numeric', 'min:0'],
            'items.*.item_type' => ['required', 'in:sewa,deposit,listrik,air,denda,lain_lain'],
        ];
    }
}
