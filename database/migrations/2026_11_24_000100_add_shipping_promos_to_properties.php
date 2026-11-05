<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Shipping discounts of a product, by destination (App\Shipping\ShippingPromos). */
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->json('shipping_promos')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('shipping_promos');
        });
    }
};
