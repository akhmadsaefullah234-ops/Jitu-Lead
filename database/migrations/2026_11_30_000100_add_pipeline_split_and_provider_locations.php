<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - tenants.pipeline_settings: Pipeline display options ("Pisahkan Dikemas dan Dikirim"; null = the default).
 * - leads.provider_location_*: the buyer's place matched once to the shipping provider's own location
 *   (App\Shipping\Shipments\ProviderLocations): exact, several candidates, or not found.
 * - provider_location_corrections: an agency's own choices (our kelurahan -> the provider's place), reused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('pipeline_settings')->nullable();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->string('provider_location_state', 20)->nullable();
            $table->string('provider_location_id', 64)->nullable();
            $table->string('provider_location_label', 250)->nullable();
            $table->json('provider_location_candidates')->nullable();
            $table->string('provider_location_note', 500)->nullable();
            $table->string('provider_location_key', 64)->nullable();
            $table->timestamp('provider_location_at')->nullable();
            $table->index(['tenant_id', 'provider_location_state']);
        });

        Schema::create('provider_location_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('region_key', 250);
            $table->string('postal_code', 10)->nullable();
            $table->string('kelurahan', 120)->nullable();
            $table->string('kecamatan', 120)->nullable();
            $table->string('kota', 120)->nullable();
            $table->string('location_id', 64);
            $table->string('location_label', 250);
            $table->string('location_postal', 10)->nullable();
            $table->json('location')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'provider', 'region_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_location_corrections');

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'provider_location_state']);
            $table->dropColumn(['provider_location_state', 'provider_location_id', 'provider_location_label', 'provider_location_candidates',
                'provider_location_note', 'provider_location_key', 'provider_location_at']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('pipeline_settings');
        });
    }
};
