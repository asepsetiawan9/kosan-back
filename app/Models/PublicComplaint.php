<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class PublicComplaint extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'public_complaints';

    protected $fillable = [
        'reporter_name',
        'reporter_phone',
        'property_id',
        'room_number',
        'category',
        'description',
        'photos',
        'status',
        'admin_response',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'photos' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * Dapatkan daftar URL publik dari seluruh foto yang diunggah.
     *
     * @return array<int, string>
     */
    public function getPhotoUrlsAttribute(): array
    {
        if (empty($this->photos) || !is_array($this->photos)) {
            return [];
        }

        return array_map(function ($path) {
            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                return $path;
            }
            return Storage::disk('public')->url($path);
        }, $this->photos);
    }
}
