<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StorePublicComplaintRequest;
use App\Http\Resources\PublicComplaintResource;
use App\Services\PublicComplaintService;
use Illuminate\Http\JsonResponse;

class PublicComplaintController extends Controller
{
    /**
     * Submit aduan publik dari halaman beranda.
     */
    public function store(
        StorePublicComplaintRequest $request,
        PublicComplaintService $service
    ): JsonResponse {
        $photoFiles = $request->file('photos') ?? [];
        if (!is_array($photoFiles)) {
            $photoFiles = [$photoFiles];
        }

        $complaint = $service->submitComplaint(
            $request->validated(),
            $photoFiles
        );

        return (new PublicComplaintResource($complaint))
            ->additional([
                'message' => 'Aduan Anda berhasil dikirim. Tim pengelola kos akan segera menindaklanjuti keluhan Anda.',
            ])
            ->response()
            ->setStatusCode(201);
    }
}
