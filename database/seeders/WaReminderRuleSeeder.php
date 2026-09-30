<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\WaReminderRule;
use Illuminate\Database\Seeder;

class WaReminderRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            [
                'name' => 'Pengingat H-1 Jatuh Tempo',
                'trigger_type' => 'before_due',
                'offset_days' => 1,
                'send_time' => '09:00:00',
                'template_key' => 'reminder_h_minus_1',
                'is_active' => true,
            ],
            [
                'name' => 'Hari Jatuh Tempo (H-0)',
                'trigger_type' => 'on_due',
                'offset_days' => 0,
                'send_time' => '09:00:00',
                'template_key' => 'reminder_due_today',
                'is_active' => true,
            ],
            [
                'name' => 'Reminder 1 Keterlambatan (H+5)',
                'trigger_type' => 'after_due',
                'offset_days' => 5,
                'send_time' => '09:00:00',
                'template_key' => 'reminder_h_plus_5',
                'is_active' => true,
            ],
            [
                'name' => 'Reminder 2 Keterlambatan (H+10)',
                'trigger_type' => 'after_due',
                'offset_days' => 10,
                'send_time' => '09:00:00',
                'template_key' => 'reminder_h_plus_10',
                'is_active' => true,
            ],
            [
                'name' => 'Peringatan Terakhir (H+15)',
                'trigger_type' => 'after_due',
                'offset_days' => 15,
                'send_time' => '09:00:00',
                'template_key' => 'reminder_h_plus_15',
                'is_active' => true,
            ],
        ];

        foreach ($rules as $rule) {
            WaReminderRule::updateOrCreate(
                [
                    'trigger_type' => $rule['trigger_type'],
                    'offset_days' => $rule['offset_days'],
                ],
                [
                    'name' => $rule['name'],
                    'send_time' => $rule['send_time'],
                    'template_key' => $rule['template_key'],
                    'is_active' => $rule['is_active'],
                ]
            );
        }
    }
}
