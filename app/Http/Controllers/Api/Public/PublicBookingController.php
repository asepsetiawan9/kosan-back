<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;

class PublicBookingController extends Controller
{
    /**
     * Submit pengajuan sewa/booking oleh publik.
     */
    public function store(StoreBookingRequest $request, BookingService $bookingService): JsonResponse
    {
        $booking = $bookingService->createBooking(
            $request->validated(),
            $request->file('ktp_file')
        );

        return (new BookingResource($booking))
            ->response()
            ->setStatusCode(201);
    }
}
