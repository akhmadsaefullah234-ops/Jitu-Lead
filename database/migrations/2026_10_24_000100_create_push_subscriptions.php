<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per browser/phone that allowed notifications. Tied to the person, not the agency.
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->string('p256dh');
            $table->string('auth');
            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->string('device', 120)->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_leads')->default(true);
            $table->boolean('notify_messages')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notify_leads', 'notify_messages']);
        });
        Schema::dropIfExists('push_subscriptions');
    }
};
