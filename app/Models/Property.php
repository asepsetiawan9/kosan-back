<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Property extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'address',
        'city',
        'province',
        'postal_code',
        'latitude',
        'longitude',
        'google_maps_url',
        'owner_name',
        'owner_phone',
        'owner_email',
        'managed_by',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'managed_by');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(PropertyMedia::class)->orderBy('sort_order', 'asc');
    }

    public function featuredMedia(): HasMany
    {
        return $this->hasMany(PropertyMedia::class)->where('is_featured', true)->orderBy('sort_order', 'asc');
    }

    public function getTotalRoomsAttribute(): int
    {
        return $this->relationLoaded('rooms') 
            ? $this->rooms->count() 
            : $this->rooms()->count();
    }

    public function getAvailableRoomsAttribute(): int
    {
        return $this->relationLoaded('rooms')
            ? $this->rooms->where('status', 'kosong')->count()
            : $this->rooms()->where('status', 'kosong')->count();
    }

    public function getOccupiedRoomsAttribute(): int
    {
        return $this->relationLoaded('rooms')
            ? $this->rooms->where('status', 'terisi')->count()
            : $this->rooms()->where('status', 'terisi')->count();
    }

    public function getMinPriceAttribute(): ?float
    {
        $rooms = $this->relationLoaded('rooms') ? $this->rooms : $this->rooms()->get();
        if ($rooms->isEmpty()) {
            return null;
        }

        $min = $rooms->min('base_price');
        return $min !== null ? (float) $min : null;
    }

    public function getMaxPriceAttribute(): ?float
    {
        $rooms = $this->relationLoaded('rooms') ? $this->rooms : $this->rooms()->get();
        if ($rooms->isEmpty()) {
            return null;
        }

        $max = $rooms->max('base_price');
        return $max !== null ? (float) $max : null;
    }
}

