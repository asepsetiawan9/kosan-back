<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;

class ContractPolicy
{
    public function view(User $user, Contract $contract): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $contract->tenancy !== null && $contract->tenancy->user_id === $user->id;
    }

    public function sign(User $user, Contract $contract): bool
    {
        if ($user->isAdmin()) {
            return false;
        }

        return $contract->tenancy !== null 
            && $contract->tenancy->user_id === $user->id 
            && $contract->signed_at === null;
    }
}
