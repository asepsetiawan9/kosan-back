<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WaMessage extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'direction',
        'tenant_id',
        'phone',
        'provider',
        'provider_message_id',
        'type',
        'body',
        'media_path',
        'template_key',
        'status',
        'error_message',
        'attempts',
        'related_type',
        'related_id',
        'raw_payload',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'raw_payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function isOutbound(): bool
    {
        return $this->direction === 'out';
    }

    public function isInbound(): bool
    {
        return $this->direction === 'in';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isSent(): bool
    {
        return in_array($this->status, ['sent', 'delivered'], true);
    }
}
