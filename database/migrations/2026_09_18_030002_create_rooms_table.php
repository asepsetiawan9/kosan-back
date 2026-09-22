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
        Schema::create('rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('room_number')->unique();
            $table->string('name');
            $table->enum('type', ['standar', 'deluxe', 'vip', 'paviliun']);
            $table->decimal('base_price', 12, 2);
            $table->text('description')->nullable();
            $table->enum('status', ['kosong', 'dipesan', 'terisi', 'maintenance'])->default('kosong');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
