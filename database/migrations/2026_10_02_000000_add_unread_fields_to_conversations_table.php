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
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('last_read_at')->nullable()->after('last_message_at');
            $table->timestamp('marked_unread_at')->nullable()->after('last_read_at');
            $table->unsignedInteger('unread_count')->default(0)->after('marked_unread_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['last_read_at', 'marked_unread_at', 'unread_count']);
        });
    }
};
