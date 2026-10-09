<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_settings', function (Blueprint $table) {
            $table->string('meta_event', 40)->default('Lead');
            $table->string('tiktok_event', 40)->default('SubmitForm');
            $table->string('google_event', 40)->default('generate_lead');
        });
    }

    public function down(): void
    {
        Schema::table('tracking_settings', function (Blueprint $table) {
            $table->dropColumn(['meta_event', 'tiktok_event', 'google_event']);
        });
    }
};
