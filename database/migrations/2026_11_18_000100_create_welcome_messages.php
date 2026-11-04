<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The automatic WhatsApp welcome to a buyer who sent a lead form (App\Welcome): its settings per form, per
 * landing page and per product, one row per lead and form that holds when it goes out and what came of it,
 * and where a chat message came from ("welcome") so the Inbox can say so.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Existing forms stay off: null means "not switched on".
        Schema::table('lead_forms', fn (Blueprint $table) => $table->json('welcome')->nullable());
        Schema::table('landing_pages', fn (Blueprint $table) => $table->json('welcome')->nullable());
        Schema::table('properties', fn (Blueprint $table) => $table->text('welcome_text')->nullable());
        Schema::table('wa_messages', fn (Blueprint $table) => $table->string('origin', 20)->nullable());

        Schema::create('welcome_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('landing_page_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('returning')->default(false);
            $table->string('status', 10)->default('pending');
            $table->timestamp('due_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->json('context')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('wa_message_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['lead_id', 'lead_form_id']);
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('welcome_messages');
        Schema::table('wa_messages', fn (Blueprint $table) => $table->dropColumn('origin'));
        Schema::table('properties', fn (Blueprint $table) => $table->dropColumn('welcome_text'));
        Schema::table('landing_pages', fn (Blueprint $table) => $table->dropColumn('welcome'));
        Schema::table('lead_forms', fn (Blueprint $table) => $table->dropColumn('welcome'));
    }
};
