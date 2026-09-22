<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordHasBeenChanged
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isTenant() && $user->must_change_password) {
            // Kecualikan rute ganti password dan logout
            if ($request->is('api/tenant/change-password') || $request->is('api/logout')) {
                return $next($request);
            }

            return response()->json([
                'message' => 'Anda wajib mengganti kata sandi sementara terlebih dahulu demi keamanan akun Anda.',
                'must_change_password' => true,
            ], Response::HTTP_PRECONDITION_REQUIRED); // 428
        }

        return $next($request);
    }
}
