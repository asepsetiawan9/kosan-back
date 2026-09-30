<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\WaReminderRule;
use App\Repositories\Contracts\WaReminderRuleRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class WaReminderRuleRepository implements WaReminderRuleRepositoryInterface
{
    public function getAll(): Collection
    {
        return WaReminderRule::with('template')
            ->withCount('logs')
            ->orderBy('trigger_type')
            ->orderBy('offset_days')
            ->get();
    }

    public function getActive(): Collection
    {
        return WaReminderRule::active()
            ->with('template')
            ->orderBy('trigger_type')
            ->orderBy('offset_days')
            ->get();
    }

    public function findById(string $id): ?WaReminderRule
    {
        return WaReminderRule::with('template')
            ->withCount('logs')
            ->find($id);
    }

    public function create(array $data): WaReminderRule
    {
        return WaReminderRule::create($data);
    }

    public function update(string $id, array $data): ?WaReminderRule
    {
        $rule = $this->findById($id);
        if (!$rule) {
            return null;
        }

        $rule->update($data);
        return $rule->fresh(['template']);
    }

    public function delete(string $id): bool
    {
        $rule = $this->findById($id);
        if (!$rule) {
            return false;
        }

        return (bool) $rule->delete();
    }

    public function toggleActive(string $id): ?WaReminderRule
    {
        $rule = $this->findById($id);
        if (!$rule) {
            return null;
        }

        $rule->is_active = !$rule->is_active;
        $rule->save();

        return $rule->fresh(['template']);
    }
}
