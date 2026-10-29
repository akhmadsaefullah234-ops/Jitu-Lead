<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', fn (Blueprint $table) => $table->json('shipping')->nullable());

        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedSmallInteger('length_cm')->nullable();
            $table->unsignedSmallInteger('width_cm')->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedInteger('shipping_cost')->nullable();
            $table->json('order_items')->nullable();
        });

        Schema::table('ai_drafts', fn (Blueprint $table) => $table->json('actions')->nullable());

        // Agencies with a starting table in config/shipping.php get it now; ProvisionTenant does it for new ones.
        foreach (config('shipping.presets', []) as $slug => $preset) {
            DB::table('tenants')->where('slug', $slug)->whereNull('shipping')->update(['shipping' => json_encode($preset)]);
        }
    }

    public function down(): void
    {
        Schema::table('ai_drafts', fn (Blueprint $table) => $table->dropColumn('actions'));
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn(['shipping_cost', 'order_items']));
        Schema::table('properties', fn (Blueprint $table) => $table->dropColumn(['weight_grams', 'length_cm', 'width_cm', 'height_cm']));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('shipping'));
    }
};
