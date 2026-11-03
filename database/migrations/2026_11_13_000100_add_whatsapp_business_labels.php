<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp Business labels from the pipeline (trial, App\WhatsApp\BusinessLabels): each gateway number's settings,
 * and one row per label a lead's chat got, so it is put on once and can be checked against Ads Manager.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_channels', function (Blueprint $table) {
            $table->json('label_settings')->nullable();
        });

        Schema::create('wa_label_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wa_channel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 60);
            $table->string('status', 12);
            $table->string('error', 120)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'lead_id', 'label']);
            $table->index(['wa_channel_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_label_events');
        Schema::table('wa_channels', fn (Blueprint $table) => $table->dropColumn('label_settings'));
    }
};
