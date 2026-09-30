<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\WaTemplate;
use Illuminate\Database\Eloquent\Collection;

interface WaTemplateRepositoryInterface
{
    public function getAll(bool $onlyActive = false): Collection;
    public function findById(string $id): ?WaTemplate;
    public function findByKey(string $key): ?WaTemplate;
    public function create(array $data): WaTemplate;
    public function update(WaTemplate $template, array $data): WaTemplate;
    public function delete(WaTemplate $template): bool;
}
