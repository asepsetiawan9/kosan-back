<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_images', function (Blueprint $table) {
            $table->enum('media_type', ['image', 'video'])->default('image')->after('image_path');
            $table->string('video_thumbnail_path')->nullable()->after('media_type');
            $table->unsignedInteger('video_duration')->nullable()->after('video_thumbnail_path'); // in seconds
        });
    }

    public function down(): void
    {
        Schema::table('room_images', function (Blueprint $table) {
            $table->dropColumn(['media_type', 'video_thumbnail_path', 'video_duration']);
        });
    }
};
