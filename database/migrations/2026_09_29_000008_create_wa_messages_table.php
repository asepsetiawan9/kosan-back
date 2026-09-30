<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('direction', ['in', 'out']);
            $table->foreignUuid('tenant_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('phone', 20);
            $table->string('provider', 50)->default('fonnte');
            $table->string('provider_message_id', 150)->nullable();
            $table->enum('type', ['text', 'image', 'document', 'other'])->default('text');
            $table->text('body')->nullable();
            $table->string('media_path')->nullable();
            $table->string('template_key')->nullable();
            $table->enum('status', ['queued', 'sent', 'failed', 'delivered', 'received', 'processed', 'ignored'])->default('queued');
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->nullableUuidMorphs('related');
            $table->json('raw_payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'direction', 'provider_message_id'], 'wa_msg_dedupe');
            $table->index(['tenant_id', 'direction']);
            $table->index('status');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_messages');
    }
};
