<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Complaint;
use App\Models\User;

class ComplaintPolicy
{
    public function view(User $user, Complaint $complaint): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $complaint->tenancy !== null && $complaint->tenancy->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        if (!$user->isTenant()) {
            return false;
        }

        return $user->tenancies()->where('status', 'aktif')->exists();
    }
}
