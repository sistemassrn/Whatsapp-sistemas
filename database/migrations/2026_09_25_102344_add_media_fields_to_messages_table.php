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
        Schema::table('messages', function (Blueprint $table) {
            $table->string('type')->default('text')->after('body');
            $table->string('media_disk')->nullable()->after('idempotency_key');
            $table->string('media_path')->nullable()->after('media_disk');
            $table->string('media_mime_type')->nullable()->after('media_path');
            $table->string('media_filename')->nullable()->after('media_mime_type');
            $table->unsignedBigInteger('media_size_bytes')->nullable()->after('media_filename');
            $table->string('media_download_status')->nullable()->after('media_size_bytes');
            $table->text('media_error')->nullable()->after('media_download_status');
            $table->text('media_metadata')->nullable()->after('media_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn([
                'type',
                'media_disk',
                'media_path',
                'media_mime_type',
                'media_filename',
                'media_size_bytes',
                'media_download_status',
                'media_error',
                'media_metadata',
            ]);
        });
    }
};
