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
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->string('openwa_last_status')->nullable()->after('maintenance_error');
            $table->timestamp('openwa_last_checked_at')->nullable()->after('openwa_last_status');
            $table->timestamp('openwa_last_ready_at')->nullable()->after('openwa_last_checked_at');
            $table->timestamp('openwa_last_recovery_sync_at')->nullable()->after('openwa_last_ready_at');
            $table->text('openwa_recovery_sync_error')->nullable()->after('openwa_last_recovery_sync_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'openwa_last_status',
                'openwa_last_checked_at',
                'openwa_last_ready_at',
                'openwa_last_recovery_sync_at',
                'openwa_recovery_sync_error',
            ]);
        });
    }
};
