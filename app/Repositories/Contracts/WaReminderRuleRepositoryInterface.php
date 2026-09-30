<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\WaReminderRule;
use Illuminate\Database\Eloquent\Collection;

interface WaReminderRuleRepositoryInterface
{
    /**
     * @return Collection<int, WaReminderRule>
     */
    public function getAll(): Collection;

    /**
     * @return Collection<int, WaReminderRule>
     */
    public function getActive(): Collection;

    public function findById(string $id): ?WaReminderRule;

    public function create(array $data): WaReminderRule;

    public function update(string $id, array $data): ?WaReminderRule;

    public function delete(string $id): bool;

    public function toggleActive(string $id): ?WaReminderRule;
}
