<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingTemplate extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'key',
        'title',
        'body',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function billingLogs(): HasMany
    {
        return $this->hasMany(BillingLog::class, 'template_id');
    }
}
