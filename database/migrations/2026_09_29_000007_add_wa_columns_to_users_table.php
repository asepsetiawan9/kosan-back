<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('wa_number', 20)->nullable()->unique()->after('phone');
            $table->boolean('wa_opt_in')->default(true)->after('wa_number');
            $table->timestamp('wa_opt_in_at')->nullable()->after('wa_opt_in');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['wa_number', 'wa_opt_in', 'wa_opt_in_at']);
        });
    }
};
