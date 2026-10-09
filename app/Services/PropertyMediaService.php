<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Property;
use App\Models\PropertyMedia;
use App\Repositories\Contracts\PropertyMediaRepositoryInterface;
use App\Repositories\Contracts\PropertyRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PropertyMediaService
{
    public function __construct(
        private readonly PropertyMediaRepositoryInterface $mediaRepository,
        private readonly PropertyRepositoryInterface $propertyRepository
    ) {}

    public function getMediaByProperty(string $propertyId): Collection
    {
        return $this->mediaRepository->getByProperty($propertyId);
    }

    public function getMediaById(string $mediaId): PropertyMedia
    {
        $media = $this->mediaRepository->findById($mediaId);
        if (!$media) {
            throw ValidationException::withMessages([
                'media' => 'Media properti tidak ditemukan.',
            ]);
        }
        return $media;
    }

    public function uploadMedia(
        Property $property,
        ?UploadedFile $file = null,
        ?UploadedFile $thumbnail = null,
        array $attributes = []
    ): PropertyMedia {
        $mediaType = $attributes['media_type'] ?? 'image';

        if ($mediaType === 'video') {
            $youtubeUrl = $attributes['youtube_url'] ?? $attributes['url'] ?? '';
            if (empty($youtubeUrl)) {
                throw ValidationException::withMessages([
                    'youtube_url' => 'Link video YouTube wajib diisi.',
                ]);
            }

            $youtubeId = $this->extractYoutubeId($youtubeUrl);
            $filePath = $youtubeUrl;

            $thumbnailPath = null;
            if ($thumbnail) {
                $thumbExt = $thumbnail->getClientOriginalExtension();
                $thumbName = Str::uuid()->toString() . '.' . $thumbExt;
                $thumbnailPath = Storage::disk('public')->putFileAs(
                    "property-media/{$property->id}/thumbnails",
                    $thumbnail,
                    $thumbName
                );
            } elseif ($youtubeId) {
                $thumbnailPath = "https://img.youtube.com/vi/{$youtubeId}/hqdefault.jpg";
            }

            $currentMedia = $this->mediaRepository->getByProperty($property->id);
            $nextSortOrder = isset($attributes['sort_order'])
                ? (int) $attributes['sort_order']
                : ($currentMedia->max('sort_order') ?? 0) + 1;

            $isFeatured = isset($attributes['is_featured']) ? (bool) $attributes['is_featured'] : false;

            return $this->mediaRepository->create([
                'property_id' => $property->id,
                'media_type' => 'video',
                'file_path' => $filePath,
                'thumbnail_path' => $thumbnailPath,
                'title' => $attributes['title'] ?? null,
                'description' => $attributes['description'] ?? null,
                'sort_order' => $nextSortOrder,
                'is_featured' => $isFeatured,
            ]);
        }

        if (!$file) {
            throw ValidationException::withMessages([
                'file' => 'Berkas gambar wajib diunggah.',
            ]);
        }

        // Validasi ukuran foto maksimal 5MB (5120 KB)
        if ($file->getSize() > 5242880) {
            throw ValidationException::withMessages([
                'file' => 'Ukuran file gambar maksimal 5MB.',
            ]);
        }

        $folder = "property-media/{$property->id}/images";
        $extension = $file->getClientOriginalExtension();
        $safeFileName = Str::uuid()->toString() . '.' . $extension;
        $filePath = Storage::disk('public')->putFileAs($folder, $file, $safeFileName);

        $thumbnailPath = null;
        if ($thumbnail) {
            $thumbExt = $thumbnail->getClientOriginalExtension();
            $thumbName = Str::uuid()->toString() . '.' . $thumbExt;
            $thumbnailPath = Storage::disk('public')->putFileAs(
                "property-media/{$property->id}/thumbnails",
                $thumbnail,
                $thumbName
            );
        }

        $currentMedia = $this->mediaRepository->getByProperty($property->id);
        $nextSortOrder = isset($attributes['sort_order'])
            ? (int) $attributes['sort_order']
            : ($currentMedia->max('sort_order') ?? 0) + 1;

        $isFeatured = isset($attributes['is_featured']) ? (bool) $attributes['is_featured'] : false;

        return $this->mediaRepository->create([
            'property_id' => $property->id,
            'media_type' => 'image',
            'file_path' => $filePath,
            'thumbnail_path' => $thumbnailPath,
            'title' => $attributes['title'] ?? null,
            'description' => $attributes['description'] ?? null,
            'sort_order' => $nextSortOrder,
            'is_featured' => $isFeatured,
        ]);
    }

    private function extractYoutubeId(string $url): ?string
    {
        $pattern = '/(?:youtube\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?)\/|\S*?[?&]v=)|youtu\.be\/|youtube\.com\/shorts\/)([a-zA-Z0-9_-]{11})/i';
        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }
        return null;
    }

    public function deleteMedia(string $propertyId, string $mediaId): bool
    {
        $media = $this->getMediaById($mediaId);

        if ($media->property_id !== $propertyId) {
            throw ValidationException::withMessages([
                'media' => 'Media tidak sesuai dengan properti yang dipilih.',
            ]);
        }

        // Hapus file fisik dari public storage jika bukan URL eksternal
        if (!str_starts_with($media->file_path, 'http')) {
            Storage::disk('public')->delete($media->file_path);
        }

        if ($media->thumbnail_path && !str_starts_with($media->thumbnail_path, 'http')) {
            Storage::disk('public')->delete($media->thumbnail_path);
        }

        return $this->mediaRepository->delete($media);
    }

    public function toggleFeatured(string $propertyId, string $mediaId, ?bool $isFeatured = null): PropertyMedia
    {
        $media = $this->getMediaById($mediaId);

        if ($media->property_id !== $propertyId) {
            throw ValidationException::withMessages([
                'media' => 'Media tidak sesuai dengan properti yang dipilih.',
            ]);
        }

        $newStatus = $isFeatured !== null ? $isFeatured : !$media->is_featured;
        return $this->mediaRepository->setFeatured($media, $newStatus);
    }

    public function reorderMedia(string $propertyId, array $orderedIds): void
    {
        $property = $this->propertyRepository->findById($propertyId);
        if (!$property) {
            throw ValidationException::withMessages([
                'property' => 'Properti tidak ditemukan.',
            ]);
        }

        $this->mediaRepository->reorder($propertyId, $orderedIds);
    }
}
