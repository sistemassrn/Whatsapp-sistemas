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
            $table->text('profile_photo_url')->nullable()->after('phone');
            $table->timestamp('profile_photo_fetched_at')->nullable()->after('profile_photo_url');
            $table->text('profile_photo_error')->nullable()->after('profile_photo_fetched_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn([
                'profile_photo_url',
                'profile_photo_fetched_at',
                'profile_photo_error',
            ]);
        });
    }
};
