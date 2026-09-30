<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class RoomImage extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'room_id',
        'image_path',
        'media_type',
        'video_thumbnail_path',
        'video_duration',
        'is_primary',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'order' => 'integer',
            'video_duration' => 'integer',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function getUrlAttribute(): string
    {
        if (str_starts_with($this->image_path, 'http://') || str_starts_with($this->image_path, 'https://')) {
            return $this->image_path;
        }

        return Storage::disk('public')->url($this->image_path);
    }

    public function getVideoThumbnailUrlAttribute(): ?string
    {
        if (!$this->video_thumbnail_path) {
            return null;
        }

        if (str_starts_with($this->video_thumbnail_path, 'http://') || str_starts_with($this->video_thumbnail_path, 'https://')) {
            return $this->video_thumbnail_path;
        }

        return Storage::disk('public')->url($this->video_thumbnail_path);
    }
}
