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
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('is_my_contact')->nullable()->after('profile_photo_error');
            $table->string('source')->nullable()->after('is_my_contact');
            $table->timestamp('last_synced_at')->nullable()->after('source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn([
                'is_my_contact',
                'source',
                'last_synced_at',
            ]);
        });
    }
};
