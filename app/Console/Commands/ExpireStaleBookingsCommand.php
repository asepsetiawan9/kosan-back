<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\Contracts\BookingRepositoryInterface;
use Illuminate\Console\Command;

class ExpireStaleBookingsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bookings:expire-stale {--days=3 : Batas hari sebelum booking kedaluwarsa}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Otomatis batalkan booking berstatus menunggu yang melebihi batas waktu';

    /**
     * Execute the console command.
     */
    public function handle(BookingRepositoryInterface $bookingRepository): int
    {
        $days = (int) $this->option('days');
        $this->info("Memeriksa booking menunggu yang melebihi {$days} hari atau telah melewati waktu kedaluwarsa...");

        $cancelledCount = $bookingRepository->cancelStalePendingBookings($days);

        $this->info("Berhasil membatalkan {$cancelledCount} booking kedaluwarsa.");

        return Command::SUCCESS;
    }
}
