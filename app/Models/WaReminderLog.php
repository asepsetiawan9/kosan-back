<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaReminderLog extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'invoice_id',
        'rule_id',
        'sent_for_date',
        'wa_message_id',
    ];

    protected function casts(): array
    {
        return [
            'sent_for_date' => 'date',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(WaReminderRule::class, 'rule_id');
    }

    public function waMessage(): BelongsTo
    {
        return $this->belongsTo(WaMessage::class, 'wa_message_id');
    }
}
