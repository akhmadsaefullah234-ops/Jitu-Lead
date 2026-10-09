<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_channels', function (Blueprint $table) {
            $table->string('ai_mode')->default('off');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->text('ai_instructions')->nullable();
            $table->text('ai_handoff_keywords')->nullable();
        });

        Schema::create('ai_knowledge_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('question')->nullable();
            $table->text('content');
            $table->string('source')->default('manual');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'active']);
        });

        Schema::create('ai_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->text('question');
            $table->text('answer');
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('ai_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('wa_conversations')->cascadeOnDelete();
            $table->foreignId('message_id')->unique()->constrained('wa_messages')->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->string('status')->default('pending');
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_drafts');
        Schema::dropIfExists('ai_suggestions');
        Schema::dropIfExists('ai_knowledge_items');
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['ai_instructions', 'ai_handoff_keywords']));
        Schema::table('wa_channels', fn (Blueprint $t) => $t->dropColumn('ai_mode'));
    }
};
