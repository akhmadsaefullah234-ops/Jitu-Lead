<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Page-wide look of a landing page, such as the space between sections (App\LandingPages\PageLayout). Empty keeps today's look. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            $table->json('layout')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('landing_pages', fn (Blueprint $table) => $table->dropColumn('layout'));
    }
};
