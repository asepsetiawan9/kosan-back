<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Room extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'room_number',
        'name',
        'type',
        'base_price',
        'description',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
        ];
    }

    public function images(): HasMany
    {
        return $this->hasMany(RoomImage::class)->orderBy('is_primary', 'desc')->orderBy('order', 'asc');
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(RoomImage::class)->ofMany([
            'is_primary' => 'max',
            'order' => 'min',
        ]);
    }

    public function facilities(): BelongsToMany
    {
        return $this->belongsToMany(Facility::class, 'facility_room')->withTimestamps();
    }

    public function tenancies(): HasMany
    {
        return $this->hasMany(Tenancy::class);
    }

    public function activeTenancy(): HasOne
    {
        return $this->hasOne(Tenancy::class)->where('status', 'aktif');
    }

    public function isAvailable(): bool
    {
        return $this->status === 'kosong';
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class)->orderBy('created_at', 'desc');
    }
}
