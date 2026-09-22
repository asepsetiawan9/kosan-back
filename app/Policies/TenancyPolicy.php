<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tenancy;
use App\Models\User;

class TenancyPolicy
{
    public function view(User $user, Tenancy $tenancy): bool
    {
        return $user->isAdmin() || $tenancy->user_id === $user->id;
    }
}
