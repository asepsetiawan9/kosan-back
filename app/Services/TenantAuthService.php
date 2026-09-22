<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TenantAuthService
{
    /**
     * Autentikasi penghuni via Email atau No. HP.
     */
    public function login(string $login, string $password): array
    {
        $loginField = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        $user = User::where($loginField, $login)->first();

        // Jika tidak ditemukan dengan format phone asli, coba variasi format 62 / 08
        if (!$user && $loginField === 'phone') {
            $trimmed = ltrim($login, '+');
            if (str_starts_with($trimmed, '62')) {
                $altPhone = '0' . substr($trimmed, 2);
                $user = User::where('phone', $altPhone)->first();
            } elseif (str_starts_with($trimmed, '0')) {
                $altPhone = '62' . substr($trimmed, 1);
                $user = User::where('phone', $altPhone)->first();
            }
        }

        if (!$user || !Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['Kredensial yang Anda masukkan tidak cocok dengan data kami.'],
            ]);
        }

        if (!$user->isTenant()) {
            throw ValidationException::withMessages([
                'login' => ['Akses ditolak. Akun ini bukan akun penghuni.'],
            ]);
        }

        $token = $user->createToken('tenant-auth-token', ['role:penyewa'])->plainTextToken;

        return [
            'user' => $user,
            'token' => $token,
            'must_change_password' => $user->must_change_password,
        ];
    }

    /**
     * Ganti kata sandi wajib penghuni (first login enforce).
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (!Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Kata sandi lama yang dimasukkan tidak sesuai.'],
            ]);
        }

        $user->update([
            'password' => Hash::make($newPassword),
            'must_change_password' => false,
        ]);
    }
}
