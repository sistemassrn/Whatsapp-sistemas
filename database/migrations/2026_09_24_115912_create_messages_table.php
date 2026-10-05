<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('direction');
            $table->text('body')->nullable();
            $table->string('status')->default('received');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('error_message')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamps();

            if (DB::connection()->getDriverName() !== 'sqlsrv') {
                $table->unique('external_id', 'messages_external_id_unique');
                $table->unique('idempotency_key', 'messages_idempotency_key_unique');
            }
        });

        if (DB::connection()->getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX messages_external_id_unique ON messages (external_id) WHERE external_id IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX messages_idempotency_key_unique ON messages (idempotency_key) WHERE idempotency_key IS NOT NULL');
        }

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreign('last_message_id', 'conversations_last_message_id_foreign')
                ->references('id')
                ->on('messages');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            DB::statement("
                IF EXISTS (
                    SELECT 1
                    FROM sys.foreign_keys
                    WHERE name = 'conversations_last_message_id_foreign'
                )
                ALTER TABLE conversations
                DROP CONSTRAINT conversations_last_message_id_foreign
            ");
        } else {
            Schema::table('conversations', function (Blueprint $table) {
                $table->dropForeign('conversations_last_message_id_foreign');
            });
        }

        Schema::dropIfExists('messages');
    }
};
