<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenancy_id')->constrained('tenancies')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignUuid('template_id')->nullable()->constrained('billing_templates')->nullOnDelete();
            $table->text('rendered_msg');
            $table->string('phone_target');
            $table->string('channel')->default('wa_web'); // 'wa_web', 'sms', 'manual'
            $table->foreignUuid('admin_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_logs');
    }
};
