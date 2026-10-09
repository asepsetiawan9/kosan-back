<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'invoice_id',
        'amount',
        'method',
        'source',
        'gateway_provider',
        'gateway_transaction_id',
        'proof_file',
        'proof_mime',
        'proof_size',
        'proof_sha256',
        'wa_message_id',
        'claimed_amount',
        'is_duplicate_suspect',
        'reject_reason',
        'status',
        'verified_at',
        'verified_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'claimed_amount' => 'decimal:2',
            'is_duplicate_suspect' => 'boolean',
            'proof_size' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isFromWhatsApp(): bool
    {
        return $this->source === 'whatsapp';
    }
}
