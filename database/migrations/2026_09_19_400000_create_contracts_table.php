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
        Schema::create('contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenancy_id')->constrained('tenancies')->cascadeOnDelete();
            $table->string('contract_number')->unique();
            $table->string('file_path');
            $table->string('signature_image')->nullable();
            $table->string('signed_file_path')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->enum('status', ['draf', 'dikirim', 'ditandatangani'])->default('draf');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenancy_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
