<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gateway numbers that drop: since when a number is down and when its admins were told (App\WhatsApp\ChannelAlerts),
 * so each outage is emailed once and its recovery once. WhatsApp Business labels the account does not have yet wait
 * (status waiting_label, up to 7 days) instead of failing. In-app notifications (the Filament bell) need Laravel's
 * notifications table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_channels', function (Blueprint $table) {
            $table->timestamp('down_since')->nullable();
            $table->timestamp('down_alerted_at')->nullable();
        });

        Schema::table('wa_label_events', function (Blueprint $table) {
            $table->string('status', 20)->change();
            $table->timestamp('waiting_since')->nullable();
            $table->index(['status', 'wa_channel_id']);
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->json('data'); // json, not text: Filament filters it with ->> on PostgreSQL
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');

        Schema::table('wa_label_events', function (Blueprint $table) {
            $table->dropIndex(['status', 'wa_channel_id']);
            $table->dropColumn('waiting_since');
            $table->string('status', 12)->change();
        });

        Schema::table('wa_channels', fn (Blueprint $table) => $table->dropColumn(['down_since', 'down_alerted_at']));
    }
};
