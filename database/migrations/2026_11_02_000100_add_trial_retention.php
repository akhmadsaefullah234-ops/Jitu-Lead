<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // WhatsApp number as 62812..., and when the person agreed to be contacted about their account and offers.
            $table->string('whatsapp', 20)->nullable()->after('email');
            $table->timestamp('contact_consent_at')->nullable()->after('whatsapp');
            $table->timestamp('whatsapp_prompt_dismissed_at')->nullable()->after('contact_consent_at');
            $table->timestamp('trial_emails_unsubscribed_at')->nullable()->after('whatsapp_prompt_dismissed_at');
        });

        Schema::table('tenants', function (Blueprint $table) {
            // The lead for this agency in the platform owner's own agency (PLATFORM_SALES_TENANT).
            $table->foreignId('sales_lead_id')->nullable()->constrained('leads')->nullOnDelete();
        });

        // One row per agency, email and anchor date (sign-up day or trial end day), so each email goes out once
        // and a trial that is extended gets its ending reminders again.
        Schema::create('trial_email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('step', 40);
            $table->date('anchor');
            $table->unsignedInteger('recipients')->default(0);
            $table->string('status', 20)->default('sent');
            $table->timestamps();
            $table->unique(['tenant_id', 'step', 'anchor']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trial_email_logs');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_lead_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['whatsapp', 'contact_consent_at', 'whatsapp_prompt_dismissed_at', 'trial_emails_unsubscribed_at']);
        });
    }
};
