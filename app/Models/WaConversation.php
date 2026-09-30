<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaConversation extends Model
{
    use HasUuids;

    protected $table = 'wa_conversations';

    protected $fillable = [
        'phone',
        'tenant_id',
        'state',
        'context',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && Carbon::now()->greaterThan($this->expires_at);
    }

    public function resetToIdle(): void
    {
        $this->update([
            'state' => 'idle',
            'context' => null,
            'expires_at' => null,
        ]);
    }

    public function setAwaitingInvoiceChoice(array $invoiceIds, ?array $pendingMedia, int $ttlMinutes = 30): void
    {
        $this->update([
            'state' => 'awaiting_invoice_choice',
            'context' => [
                'invoice_ids' => $invoiceIds,
                'pending_media' => $pendingMedia,
                'invalid_attempts' => 0,
            ],
            'expires_at' => Carbon::now()->addMinutes($ttlMinutes),
        ]);
    }
}
