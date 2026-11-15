<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // What a property client buys for (hunian, investasi, villa, sewa) and where they live; the AI's simpan_kebutuhan fills them.
            $table->string('purpose', 40)->nullable();
            $table->string('domicile', 120)->nullable();
            // When the survey date was last set: a reminder due before that is not sent late.
            $table->timestamp('survey_scheduled_at')->nullable();
        });

        Schema::table('follow_up_logs', function (Blueprint $table) {
            // The survey a reminder was for: a new date gets its own reminder.
            $table->timestamp('survey_at')->nullable();
            $table->dropUnique(['follow_up_rule_id', 'lead_id', 'stage_id']);
        });

        // Still once per rule, lead and stage, and for a survey reminder once per survey date.
        DB::statement("CREATE UNIQUE INDEX follow_up_logs_once ON follow_up_logs (follow_up_rule_id, lead_id, stage_id, COALESCE(survey_at, '1970-01-01 00:00:00'))");
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'domicile', 'survey_scheduled_at']);
        });

        DB::statement('DROP INDEX follow_up_logs_once');

        Schema::table('follow_up_logs', function (Blueprint $table) {
            $table->dropColumn('survey_at');
            $table->unique(['follow_up_rule_id', 'lead_id', 'stage_id']);
        });
    }
};
