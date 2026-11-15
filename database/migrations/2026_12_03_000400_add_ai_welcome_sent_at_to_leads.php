<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** When the first automatic AI reply carried the agency's welcome brochure and price list, so it goes once. */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('ai_welcome_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('ai_welcome_sent_at');
        });
    }
};
