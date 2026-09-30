<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Jalankan pengingat tagihan WhatsApp otomatis setiap 15 menit
\Illuminate\Support\Facades\Schedule::command('wa:run-reminders')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Reset percakapan chatbot WhatsApp yang melewati batas TTL (30 menit) setiap 5 menit
\Illuminate\Support\Facades\Schedule::command('wa:expire-conversations')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Pembersihan payload mentah WhatsApp > 90 hari setiap pekan (Minggu pukul 02:00)
\Illuminate\Support\Facades\Schedule::command('wa:cleanup-payloads --days=90')
    ->weeklyOn(0, '02:00')
    ->withoutOverlapping();

// Batalkan booking kamar kedaluwarsa setiap jam
\Illuminate\Support\Facades\Schedule::command('bookings:expire-stale')
    ->hourly()
    ->withoutOverlapping();

// Terbitkan tagihan sewa bulanan otomatis & tandai tagihan lewat jatuh tempo
\Illuminate\Support\Facades\Schedule::command('invoices:generate-monthly')
    ->dailyAt('00:01')
    ->withoutOverlapping();

\Illuminate\Support\Facades\Schedule::command('invoices:mark-overdue')
    ->dailyAt('00:05')
    ->withoutOverlapping();

