<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracking numbers made outside JITU LEAD (in the courier aggregator's own app) linked to a lead: source "manual",
 * no order at the provider. Tracking that does not know the number is counted and stops after three tries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('source', 10)->default('auto');
            $table->unsignedSmallInteger('track_failures')->default(0);
            $table->timestamp('untrackable_at')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->index(['tenant_id', 'awb']);
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'awb']);
            $table->dropColumn(['source', 'track_failures', 'untrackable_at', 'linked_at']);
        });
    }
};
