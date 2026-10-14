<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_up_rules', function (Blueprint $table) {
            $table->foreignId('media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
        });

        Schema::table('ai_drafts', function (Blueprint $table) {
            $table->json('media_asset_ids')->nullable(); // library items the AI suggests sending with the answer
        });
    }

    public function down(): void
    {
        Schema::table('ai_drafts', fn (Blueprint $table) => $table->dropColumn('media_asset_ids'));
        Schema::table('follow_up_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('media_asset_id');
        });
    }
};
