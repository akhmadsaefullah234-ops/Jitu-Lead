<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database dumps now run on their own every few hours (backup:database) beside the nightly full run, so a run says
 * which kind it is and, when its dump passed `gzip -t`, where that dump is and how big. Only adds columns; old rows
 * are full runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->string('kind', 12)->default('full')->after('id'); // full | database
            $table->string('database_file')->nullable()->after('folder');
            $table->unsignedBigInteger('database_bytes')->nullable()->after('database_file');
            $table->index(['kind', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->dropIndex(['kind', 'started_at']);
            $table->dropColumn(['kind', 'database_file', 'database_bytes']);
        });
    }
};
