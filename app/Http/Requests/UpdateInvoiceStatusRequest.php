<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'in:belum_bayar,sebagian_dibayar,menunggu_verifikasi,lunas,terlambat,dibatalkan',
            ],
        ];
    }
}
