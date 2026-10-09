<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PropertyMedia extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'property_media';

    protected $fillable = [
        'property_id',
        'media_type',
        'file_path',
        'thumbnail_path',
        'title',
        'description',
        'sort_order',
        'is_featured',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_featured' => 'boolean',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function getYoutubeIdAttribute(): ?string
    {
        if ($this->media_type !== 'video' || empty($this->file_path)) {
            return null;
        }

        $pattern = '/(?:youtube\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?)\/|\S*?[?&]v=)|youtu\.be\/|youtube\.com\/shorts\/)([a-zA-Z0-9_-]{11})/i';
        if (preg_match($pattern, $this->file_path, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function getEmbedUrlAttribute(): ?string
    {
        $id = $this->youtube_id;
        if (!$id) {
            return null;
        }

        return "https://www.youtube-nocookie.com/embed/{$id}";
    }

    public function getUrlAttribute(): string
    {
        if (str_starts_with($this->file_path, 'http://') || str_starts_with($this->file_path, 'https://')) {
            return $this->file_path;
        }

        return Storage::disk('public')->url($this->file_path);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if (!$this->thumbnail_path) {
            if ($this->media_type === 'video' && $this->youtube_id) {
                return "https://img.youtube.com/vi/{$this->youtube_id}/hqdefault.jpg";
            }
            return null;
        }

        if (str_starts_with($this->thumbnail_path, 'http://') || str_starts_with($this->thumbnail_path, 'https://')) {
            return $this->thumbnail_path;
        }

        return Storage::disk('public')->url($this->thumbnail_path);
    }
}
