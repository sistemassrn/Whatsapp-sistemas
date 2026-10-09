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
        if (! Schema::hasColumn('messages', 'media_next_retry_at')) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->timestamp('media_next_retry_at')->nullable()->after('media_error');
            });
        }

        if (! Schema::hasColumn('messages', 'media_retry_attempts')) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->unsignedInteger('media_retry_attempts')->default(0)->after('media_next_retry_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('messages', 'media_next_retry_at')) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->dropColumn('media_next_retry_at');
            });
        }

        if (Schema::hasColumn('messages', 'media_retry_attempts')) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->dropColumn('media_retry_attempts');
            });
        }
    }
};
