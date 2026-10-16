<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Promo email series the platform owner writes and starts by hand in /admin (Kampanye email).
        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('segment', 30);
            // [{subject, body, button, url}], 1 to 5 emails sent interval_days apart.
            $table->json('steps');
            $table->unsignedSmallInteger('interval_days')->default(3);
            $table->timestamp('starts_at');
            $table->boolean('stop_when_subscribed')->default(true);
            $table->string('status', 20)->default('draft');
            // When the audience of the first email was fixed; later emails go only to people who got the one before.
            $table->timestamp('audience_frozen_at')->nullable();
            $table->timestamps();
        });

        // One row per campaign, email and person: the record that makes sending idempotent and feeds the statistics.
        Schema::create('email_campaign_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_campaign_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('step');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // pending, queued, sent, failed, skipped (no longer eligible), cancelled (campaign stopped)
            $table->string('status', 20)->default('pending');
            $table->string('error', 250)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
            $table->unique(['email_campaign_id', 'step', 'user_id']);
            $table->index(['status', 'queued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaign_sends');
        Schema::dropIfExists('email_campaigns');
    }
};
