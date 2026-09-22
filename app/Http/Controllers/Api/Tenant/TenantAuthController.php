<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\ChangePasswordRequest;
use App\Http\Requests\Tenant\TenantLoginRequest;
use App\Http\Resources\UserResource;
use App\Services\TenantAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantAuthController extends Controller
{
    public function __construct(
        protected TenantAuthService $authService
    ) {}

    /**
     * Login penghuni via Email atau Nomor Handphone.
     */
    public function login(TenantLoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(
            $request->input('login'),
            $request->input('password')
        );

        return response()->json([
            'message' => 'Login penghuni berhasil.',
            'token' => $result['token'],
            'must_change_password' => $result['must_change_password'],
            'user' => new UserResource($result['user']),
        ]);
    }

    /**
     * Ganti kata sandi wajib saat login pertama atau mandiri.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->authService->changePassword(
            $request->user(),
            $request->input('current_password'),
            $request->input('new_password')
        );

        return response()->json([
            'message' => 'Kata sandi berhasil diperbarui. Anda sekarang dapat mengakses seluruh menu portal.',
        ]);
    }
}
