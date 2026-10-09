<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 120);
            $table->string('status', 12)->default('open');
            // Who wrote last, and whether the other side has read it.
            $table->boolean('last_from_staff')->default(false);
            $table->boolean('staff_unread')->default(false);
            $table->boolean('user_unread')->default(false);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'status']);
            $table->index(['status', 'staff_unread']);
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('support_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('from_staff')->default(false);
            $table->text('body');
            $table->timestamps();
            $table->index(['support_thread_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_threads');
    }
};
