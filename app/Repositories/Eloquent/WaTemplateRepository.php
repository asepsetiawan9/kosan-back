<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\WaTemplate;
use App\Repositories\Contracts\WaTemplateRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class WaTemplateRepository implements WaTemplateRepositoryInterface
{
    public function getAll(bool $onlyActive = false): Collection
    {
        $query = WaTemplate::orderBy('key');

        if ($onlyActive) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    public function findById(string $id): ?WaTemplate
    {
        return WaTemplate::find($id);
    }

    public function findByKey(string $key): ?WaTemplate
    {
        return WaTemplate::where('key', $key)->first();
    }

    public function create(array $data): WaTemplate
    {
        return WaTemplate::create($data);
    }

    public function update(WaTemplate $template, array $data): WaTemplate
    {
        $template->update($data);
        return $template->fresh();
    }

    public function delete(WaTemplate $template): bool
    {
        return (bool) $template->delete();
    }
}
