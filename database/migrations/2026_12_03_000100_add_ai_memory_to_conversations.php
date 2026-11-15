<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_conversations', function (Blueprint $table) {
            // What the AI remembers of this chat (App\Ai\ConversationMemory), as JSON fields; never instructions.
            $table->text('ai_memory')->nullable();
            // The newest message the memory covers, and when it was written.
            $table->unsignedBigInteger('ai_memory_message_id')->nullable();
            $table->timestamp('ai_memory_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wa_conversations', function (Blueprint $table) {
            $table->dropColumn(['ai_memory', 'ai_memory_message_id', 'ai_memory_at']);
        });
    }
};
