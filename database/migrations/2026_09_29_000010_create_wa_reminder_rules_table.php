<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_reminder_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->enum('trigger_type', ['before_due', 'on_due', 'after_due']);
            $table->unsignedSmallInteger('offset_days')->default(0);
            $table->time('send_time')->default('09:00');
            $table->string('template_key');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['trigger_type', 'is_active']);
            $table->index('template_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_reminder_rules');
    }
};
