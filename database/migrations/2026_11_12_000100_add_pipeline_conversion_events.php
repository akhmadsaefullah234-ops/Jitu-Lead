<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversion events from the CRM itself (App\Tracking\PipelineConversions): which stage or "Hot" sends which
 * event, the last result per platform, and one row per event a lead sent, so it is never counted twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_settings', function (Blueprint $table) {
            $table->json('pipeline_events')->nullable();
            $table->json('last_results')->nullable();
        });

        Schema::create('conversion_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_name', 40);
            $table->string('event_id', 120);
            $table->string('trigger', 20);
            $table->json('results')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_events');
        Schema::table('tracking_settings', fn (Blueprint $table) => $table->dropColumn(['pipeline_events', 'last_results']));
    }
};
