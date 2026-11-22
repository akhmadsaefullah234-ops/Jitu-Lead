<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Digital products (industry "digital"): the buyer's device and the access link sent after payment. Only adds
 * nullable columns; nothing else reads them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('device', 60)->nullable();
            $table->string('access_link', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['device', 'access_link']);
        });
    }
};
