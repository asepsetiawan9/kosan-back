<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WaReminderRule extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'trigger_type',
        'offset_days',
        'send_time',
        'template_key',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'offset_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WaTemplate::class, 'template_key', 'key');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WaReminderLog::class, 'rule_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
