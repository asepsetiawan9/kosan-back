<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\PropertyMedia;
use App\Repositories\Contracts\PropertyMediaRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PropertyMediaRepository implements PropertyMediaRepositoryInterface
{
    public function getByProperty(string $propertyId): Collection
    {
        return PropertyMedia::where('property_id', $propertyId)
            ->orderBy('sort_order', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();
    }

    public function findById(string $id): ?PropertyMedia
    {
        return PropertyMedia::with('property')->find($id);
    }

    public function create(array $data): PropertyMedia
    {
        return PropertyMedia::create($data);
    }

    public function update(PropertyMedia $media, array $data): PropertyMedia
    {
        $media->update($data);
        return $media->fresh();
    }

    public function delete(PropertyMedia $media): bool
    {
        return (bool) $media->delete();
    }

    public function reorder(string $propertyId, array $orderedIds): void
    {
        DB::transaction(function () use ($propertyId, $orderedIds) {
            foreach ($orderedIds as $index => $id) {
                PropertyMedia::where('property_id', $propertyId)
                    ->where('id', $id)
                    ->update(['sort_order' => $index + 1]);
            }
        });
    }

    public function setFeatured(PropertyMedia $media, bool $isFeatured): PropertyMedia
    {
        $media->update(['is_featured' => $isFeatured]);
        return $media->fresh();
    }
}
