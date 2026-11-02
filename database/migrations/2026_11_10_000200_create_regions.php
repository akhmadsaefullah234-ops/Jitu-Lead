<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indonesia's administrative regions (Kemendagri codes): provinces, cities/regencies, districts and villages, filled
 * by "php artisan regions:import". Shared by every agency. Leads also keep the parts of their shipping address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->string('code', 13)->primary();
            $table->string('parent_code', 8)->nullable();
            $table->unsignedTinyInteger('level');
            $table->string('name', 100);
            $table->string('search', 100);
            $table->string('postal_code', 5)->nullable();
            $table->index(['parent_code', 'name']);
            $table->index(['level', 'search']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->json('shipping_address_parts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn('shipping_address_parts'));
    }
};
