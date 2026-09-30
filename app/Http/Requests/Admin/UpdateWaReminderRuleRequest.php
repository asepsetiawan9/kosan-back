<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWaReminderRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'trigger_type' => ['sometimes', 'in:before_due,on_due,after_due'],
            'offset_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'send_time' => ['sometimes', 'date_format:H:i,H:i:s'],
            'template_key' => ['sometimes', 'string', 'exists:wa_templates,key'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
