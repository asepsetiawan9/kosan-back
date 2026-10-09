<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_complaints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reporter_name');
            $table->string('reporter_phone', 30);
            $table->foreignUuid('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->string('room_number', 50)->nullable();
            $table->enum('category', [
                'fasilitas_rusak',
                'kebersihan',
                'keamanan',
                'air_listrik',
                'lainnya',
            ]);
            $table->text('description');
            $table->json('photos')->nullable();
            $table->enum('status', ['baru', 'diproses', 'selesai', 'ditolak'])->default('baru');
            $table->text('admin_response')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['property_id', 'status']);
            $table->index(['status', 'category']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_complaints');
    }
};
