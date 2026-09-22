<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Room;
use App\Models\Tenancy;

class TenancyObserver
{
    /**
     * Handle the Tenancy "created" event.
     */
    public function created(Tenancy $tenancy): void
    {
        if ($tenancy->status === 'aktif') {
            Room::where('id', $tenancy->room_id)->update(['status' => 'terisi']);
        }
    }
}
