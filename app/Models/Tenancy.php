<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenancy extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'room_id',
        'user_id',
        'tenant_name',
        'tenant_phone',
        'tenant_email',
        'start_date',
        'end_date',
        'billing_due_day',
        'deposit_amount',
        'deposit_status',
        'status',
        'deposit_deduction',
        'deduction_reason',
        'checkout_date',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'checkout_date' => 'date',
            'billing_due_day' => 'integer',
            'deposit_amount' => 'decimal:2',
            'deposit_deduction' => 'decimal:2',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }

    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class)->latestOfMany();
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }
}

