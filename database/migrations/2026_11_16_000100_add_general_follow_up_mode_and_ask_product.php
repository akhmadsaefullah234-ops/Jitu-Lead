<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Whether the general follow-ups run (auto: not for a shop with more than one active product), the one
 * "tanya produk" message per tenant for leads without a product, the product order that message listed (so a
 * reply "2" maps to the second product), and a product per lead form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('general_follow_ups', 10)->default('auto');
        });

        Schema::table('follow_up_rules', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('property_id');
        });

        Schema::table('follow_up_logs', function (Blueprint $table) {
            $table->json('product_ids')->nullable();
        });

        Schema::table('lead_forms', function (Blueprint $table) {
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lead_forms', fn (Blueprint $table) => $table->dropConstrainedForeignId('property_id'));
        Schema::table('follow_up_logs', fn (Blueprint $table) => $table->dropColumn('product_ids'));
        Schema::table('follow_up_rules', fn (Blueprint $table) => $table->dropColumn('kind'));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('general_follow_ups'));
    }
};
