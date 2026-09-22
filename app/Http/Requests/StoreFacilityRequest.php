<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFacilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'icon_identifier' => ['nullable', 'string', 'max:100'],
            'category' => ['required', 'in:kamar,kamar_mandi,umum'],
        ];
    }
}
