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
            $table->timestamp('maintenance_started_at')->nullable()->after('last_error');
            $table->timestamp('maintenance_finished_at')->nullable()->after('maintenance_started_at');
            $table->string('maintenance_status')->nullable()->after('maintenance_finished_at');
            $table->text('maintenance_error')->nullable()->after('maintenance_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'maintenance_started_at',
                'maintenance_finished_at',
                'maintenance_status',
                'maintenance_error',
            ]);
        });
    }
};
