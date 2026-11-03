<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which product a lead asks about, from the Click-to-WhatsApp ad it came from (App\Catalog\ProductMatcher):
 * the ad on the lead, how a product recognises its ads, its strike-through price and promo text, and
 * knowledge that belongs to one product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->json('ad_keywords')->nullable();
            $table->unsignedBigInteger('compare_price')->nullable();
            $table->string('promo_text', 300)->nullable();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->string('ad_id', 100)->nullable();
            $table->string('ad_title', 255)->nullable();
            $table->string('ad_url', 500)->nullable();
            $table->string('product_source', 20)->nullable();
        });

        Schema::table('ai_knowledge_items', function (Blueprint $table) {
            $table->foreignId('property_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_knowledge_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('property_id');
        });
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn(['ad_id', 'ad_title', 'ad_url', 'product_source']));
        Schema::table('properties', fn (Blueprint $table) => $table->dropColumn(['ad_keywords', 'compare_price', 'promo_text']));
    }
};
