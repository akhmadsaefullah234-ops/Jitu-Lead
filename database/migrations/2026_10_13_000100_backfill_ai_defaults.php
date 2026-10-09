<?php

use App\Ai\Defaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Agencies created before the defaults existed get the same starting text; filled-in values are left alone. */
    public function up(): void
    {
        DB::table('tenants')->whereNull('ai_instructions')->update(['ai_instructions' => Defaults::INSTRUCTIONS]);
        DB::table('tenants')->whereNull('ai_handoff_keywords')->update(['ai_handoff_keywords' => Defaults::keywordText()]);
    }

    public function down(): void {}
};
