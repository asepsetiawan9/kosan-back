<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BookingApprovalController extends Controller
{
    public function __construct(
        protected BookingService $bookingService
    ) {}

    /**
     * List seluruh pengajuan booking untuk admin.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = [
            'status' => $request->query('status'),
            'room_id' => $request->query('room_id'),
            'search' => $request->query('search'),
        ];

        $perPage = (int) $request->query('per_page', 15);
        $bookings = $this->bookingService->getPaginated($filters, $perPage);

        return BookingResource::collection($bookings);
    }

    /**
     * Detail booking dengan signed URL preview KTP.
     */
    public function show(string $id): BookingResource|JsonResponse
    {
        $booking = $this->bookingService->findById($id);

        if (!$booking) {
            return response()->json(['message' => 'Permohonan booking tidak ditemukan.'], 404);
        }

        return new BookingResource($booking);
    }

    /**
     * Streaming / download berkas KTP aman via Temporary Signed Route (5 min expiry).
     */
    public function streamKtp(Request $request, string $id): BinaryFileResponse|JsonResponse
    {
        $booking = Booking::find($id);

        if (!$booking) {
            return response()->json(['message' => 'Data booking tidak ditemukan.'], 404);
        }

        if (!Storage::disk('local')->exists($booking->ktp_file)) {
            return response()->json(['message' => 'Berkas KTP tidak ditemukan di media penyimpanan.'], 404);
        }

        $filePath = Storage::disk('local')->path($booking->ktp_file);

        return response()->file($filePath, [
            'Cache-Control' => 'no-cache, private',
            'Content-Disposition' => 'inline; filename="ktp-' . $booking->id . '"',
        ]);
    }

    /**
     * Persetujuan booking oleh admin.
     */
    public function approve(string $id): JsonResponse
    {
        $booking = $this->bookingService->approveBooking($id);

        return response()->json([
            'message' => 'Permohonan booking berhasil disetujui. Kamar telah dialokasikan dan akun penghuni berhasil disiapkan.',
            'data' => new BookingResource($booking),
        ]);
    }

    /**
     * Penolakan booking oleh admin.
     */
    public function reject(RejectBookingRequest $request, string $id): JsonResponse
    {
        $booking = $this->bookingService->rejectBooking($id, $request->validated('reason'));

        return response()->json([
            'message' => 'Permohonan booking berhasil ditolak.',
            'data' => new BookingResource($booking),
        ]);
    }
}
