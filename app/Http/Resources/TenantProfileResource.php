<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Complaint;
use App\Models\Invoice;
use App\Models\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var \App\Models\User $user */
        $user = $this->resource;

        // Ambil tenancy aktif terbaru
        $activeTenancy = $user->tenancies()
            ->with(['room.facilities', 'room.images'])
            ->where('status', 'aktif')
            ->first();

        // Riwayat seluruh ID tenancy milik user
        $allTenancyIds = $user->tenancies()->pluck('id')->toArray();

        $unpaidInvoicesCount = 0;
        $totalUnpaidAmount = 0.0;
        if (!empty($allTenancyIds)) {
            $unpaidQuery = Invoice::whereIn('tenancy_id', $allTenancyIds)
                ->whereIn('status', ['belum_bayar', 'sebagian_dibayar', 'terlambat']);
            $unpaidInvoicesCount = $unpaidQuery->count();
            $totalUnpaidAmount = (float) $unpaidQuery->sum('total_amount') - (float) $unpaidQuery->sum('paid_amount');
        }

        $pendingComplaintsCount = 0;
        if (!empty($allTenancyIds)) {
            $pendingComplaintsCount = Complaint::whereIn('tenancy_id', $allTenancyIds)
                ->whereIn('status', ['baru', 'diproses'])
                ->count();
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'must_change_password' => $user->must_change_password,
            'has_active_tenancy' => $activeTenancy !== null,
            'active_tenancy' => $activeTenancy ? [
                'id' => $activeTenancy->id,
                'start_date' => $activeTenancy->start_date?->format('Y-m-d'),
                'end_date' => $activeTenancy->end_date?->format('Y-m-d'),
                'billing_due_day' => $activeTenancy->billing_due_day,
                'deposit_amount' => (float) $activeTenancy->deposit_amount,
                'deposit_status' => $activeTenancy->deposit_status,
                'status' => $activeTenancy->status,
                'room' => $activeTenancy->room ? [
                    'id' => $activeTenancy->room->id,
                    'room_number' => $activeTenancy->room->room_number,
                    'name' => $activeTenancy->room->name,
                    'type' => $activeTenancy->room->type,
                    'base_price' => (float) $activeTenancy->room->base_price,
                    'facilities' => FacilityResource::collection($activeTenancy->room->facilities),
                ] : null,
            ] : null,
            'stats' => [
                'unpaid_invoices_count' => $unpaidInvoicesCount,
                'total_unpaid_amount' => max(0, $totalUnpaidAmount),
                'pending_complaints_count' => $pendingComplaintsCount,
            ],
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
