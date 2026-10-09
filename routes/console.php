<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();


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

