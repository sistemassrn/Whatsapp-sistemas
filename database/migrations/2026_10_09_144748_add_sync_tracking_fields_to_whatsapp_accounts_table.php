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
            $table->string('maintenance_reason')->nullable()->after('maintenance_status');
            $table->timestamp('maintenance_requested_at')->nullable()->after('maintenance_reason');
            $table->unsignedInteger('maintenance_duration_seconds')->nullable()->after('maintenance_finished_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'maintenance_reason',
                'maintenance_requested_at',
                'maintenance_duration_seconds',
            ]);
        });
    }
};
