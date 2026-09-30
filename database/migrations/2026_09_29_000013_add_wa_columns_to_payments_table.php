<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('source', 50)->default('web')->after('method');
            $table->string('proof_mime', 100)->nullable()->after('proof_file');
            $table->unsignedInteger('proof_size')->nullable()->after('proof_mime');
            $table->string('proof_sha256', 64)->nullable()->after('proof_size');
            $table->foreignUuid('wa_message_id')->nullable()->after('proof_sha256')
                ->constrained('wa_messages')->nullOnDelete();
            $table->decimal('claimed_amount', 12, 2)->nullable()->after('wa_message_id');
            $table->boolean('is_duplicate_suspect')->default(false)->after('claimed_amount');
            $table->text('reject_reason')->nullable()->after('notes');

            $table->index('proof_sha256');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['wa_message_id']);
            $table->dropColumn([
                'source',
                'proof_mime',
                'proof_size',
                'proof_sha256',
                'wa_message_id',
                'claimed_amount',
                'is_duplicate_suspect',
                'reject_reason',
            ]);
        });
    }
};
