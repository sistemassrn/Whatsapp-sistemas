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
            $table->foreignId('reply_to_message_id')
                ->nullable()
                ->constrained('messages')
                ->noActionOnDelete();
            $table->string('quoted_message_external_id')->nullable()->index();
            $table->json('quoted_message_snapshot')->nullable();
            $table->string('accepted_reaction_emoji')->nullable();
            $table->timestamp('accepted_reaction_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropForeign(['reply_to_message_id']);
            $table->dropIndex(['quoted_message_external_id']);
            $table->dropColumn([
                'reply_to_message_id',
                'quoted_message_external_id',
                'quoted_message_snapshot',
                'accepted_reaction_emoji',
                'accepted_reaction_at',
            ]);
        });
    }
};
