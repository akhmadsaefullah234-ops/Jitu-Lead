<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The daily WhatsApp report to owners and admins (App\Reports\DailyReport): the agency's send time and days, each
 * member's own choice (null = the default for their role), the verified WhatsApp number it goes to, and one row
 * per report sent, so a report never goes out twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', fn (Blueprint $table) => $table->json('daily_report')->nullable());
        Schema::table('tenant_user', fn (Blueprint $table) => $table->json('report_settings')->nullable());

        Schema::table('users', function (Blueprint $table) {
            // The number that was confirmed with a code; it no longer counts once `whatsapp` is changed.
            $table->string('whatsapp_verified_number', 20)->nullable();
            $table->timestamp('whatsapp_verified_at')->nullable();
        });

        Schema::create('report_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // daily, weekly, monthly, sample
            $table->date('report_date'); // the agency's local date it was due
            $table->string('status', 10)->default('pending'); // pending, sending, sent (WhatsApp), email, failed
            $table->timestamp('due_at');
            $table->string('period_label')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_at']);
            $table->index(['tenant_id', 'id']);
        });

        // One report per person, agency, kind and day; "Kirim contoh sekarang" may be pressed more than once.
        DB::statement("create unique index report_deliveries_once on report_deliveries (tenant_id, user_id, kind, report_date) where kind <> 'sample'");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_deliveries');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['whatsapp_verified_number', 'whatsapp_verified_at']));
        Schema::table('tenant_user', fn (Blueprint $table) => $table->dropColumn('report_settings'));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('daily_report'));
    }
};
