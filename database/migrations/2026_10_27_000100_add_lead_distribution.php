<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_user', function (Blueprint $table) {
            // null = follow the role (agents get leads, admins and team leaders do not); true/false overrides it.
            $table->boolean('takes_leads')->nullable();
            // Share of new leads in percent; null = an equal share.
            $table->unsignedSmallInteger('lead_share')->nullable();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('lead_reassign_mode')->default('manual');
            $table->unsignedSmallInteger('lead_reassign_minutes')->default(30);
            $table->unsignedSmallInteger('lead_reassign_max')->default(2);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('assigned_at')->nullable();
            $table->unsignedSmallInteger('reassign_count')->default(0);
        });

        DB::table('leads')->whereNull('assigned_at')->update(['assigned_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn(['assigned_at', 'reassign_count']));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn(['lead_reassign_mode', 'lead_reassign_minutes', 'lead_reassign_max']));
        Schema::table('tenant_user', fn (Blueprint $table) => $table->dropColumn(['takes_leads', 'lead_share']));
    }
};
