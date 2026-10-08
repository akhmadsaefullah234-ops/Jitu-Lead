<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('status')->default('disconnected');
            $table->text('credentials')->nullable();
            $table->string('webhook_token', 64)->unique();
            $table->text('intro_template')->nullable();
            $table->unsignedSmallInteger('position')->default(1);
            $table->timestamp('last_connected_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'type', 'status']);
        });

        Schema::create('wa_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('phone');
            $table->timestamp('last_inbound_at')->nullable();
            $table->foreignId('last_inbound_channel_id')->nullable()->constrained('wa_channels')->nullOnDelete();
            $table->timestamp('ad_entry_at')->nullable();
            $table->timestamp('free_window_ends_at')->nullable();
            $table->json('ad_data')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'lead_id']);
            $table->index(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'last_message_at']);
        });

        Schema::create('wa_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('wa_conversations')->cascadeOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained('wa_channels')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction');
            $table->string('type')->default('text');
            $table->text('body')->nullable();
            $table->json('media')->nullable();
            $table->string('template_name')->nullable();
            $table->boolean('is_intro')->default(false);
            $table->boolean('is_paid')->default(false);
            $table->string('status')->default('sent');
            $table->string('external_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->index(['conversation_id', 'sent_at']);
            $table->unique(['channel_id', 'external_id']);
        });

        Schema::create('wa_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category')->default('marketing');
            $table->string('language')->default('id');
            $table->text('body');
            $table->string('status')->default('approved');
            $table->timestamps();
            $table->unique(['tenant_id', 'name', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_templates');
        Schema::dropIfExists('wa_messages');
        Schema::dropIfExists('wa_conversations');
        Schema::dropIfExists('wa_channels');
    }
};
