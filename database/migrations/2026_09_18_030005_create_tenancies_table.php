<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenancies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('room_id')->constrained('rooms')->restrictOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tenant_name');
            $table->string('tenant_phone');
            $table->string('tenant_email')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedTinyInteger('billing_due_day')->default(1);
            $table->decimal('deposit_amount', 12, 2)->default(0);
            $table->enum('deposit_status', ['ditahan', 'dikembalikan', 'dipotong'])->default('ditahan');
            $table->enum('status', ['aktif', 'selesai', 'dibatalkan'])->default('aktif');
            $table->decimal('deposit_deduction', 12, 2)->nullable();
            $table->text('deduction_reason')->nullable();
            $table->date('checkout_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenancies');
    }
};
