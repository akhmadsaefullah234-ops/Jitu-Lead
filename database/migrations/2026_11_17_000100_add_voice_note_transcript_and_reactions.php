<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_messages', function (Blueprint $table) {
            // What a voice note says, when transcription is set up (App\Ai\Transcriber).
            $table->text('transcript')->nullable();
            // Emoji reactions on this message: {"in": "👍"} from the customer, {"out": "❤"} from the business.
            $table->json('reactions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wa_messages', fn (Blueprint $table) => $table->dropColumn(['transcript', 'reactions']));
    }
};
