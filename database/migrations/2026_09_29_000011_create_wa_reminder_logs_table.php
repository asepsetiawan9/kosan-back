<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_reminder_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignUuid('rule_id')->constrained('wa_reminder_rules')->cascadeOnDelete();
            $table->date('sent_for_date');
            $table->foreignUuid('wa_message_id')->nullable()->constrained('wa_messages')->nullOnDelete();
            $table->timestamps();

            // Deduplication safeguard: exactly one reminder per invoice per rule per target date
            $table->unique(['invoice_id', 'rule_id', 'sent_for_date'], 'unique_reminder_log');
            $table->index(['rule_id', 'sent_for_date']);
            $table->index('sent_for_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_reminder_logs');
    }
};
