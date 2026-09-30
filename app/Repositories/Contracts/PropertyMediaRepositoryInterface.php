<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\PropertyMedia;
use Illuminate\Database\Eloquent\Collection;

interface PropertyMediaRepositoryInterface
{
    public function getByProperty(string $propertyId): Collection;

    public function findById(string $id): ?PropertyMedia;

    public function create(array $data): PropertyMedia;

    public function update(PropertyMedia $media, array $data): PropertyMedia;

    public function delete(PropertyMedia $media): bool;

    public function reorder(string $propertyId, array $orderedIds): void;

    public function setFeatured(PropertyMedia $media, bool $isFeatured): PropertyMedia;
}
