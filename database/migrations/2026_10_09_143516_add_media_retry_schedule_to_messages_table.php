<?php

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
        Schema::table('messages', function (Blueprint $table): void {
            $table->timestamp('media_next_retry_at')->nullable()->after('media_error');
            $table->unsignedInteger('media_retry_attempts')->default(0)->after('media_next_retry_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn([
                'media_next_retry_at',
                'media_retry_attempts',
            ]);
        });
    }
};
