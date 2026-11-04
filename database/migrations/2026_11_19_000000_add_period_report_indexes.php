<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Indexes for the period filters (Dashboard, Lead, Pipeline, Laporan): leads by date, stage moves by date. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'stage_entered_at']);
        });

        Schema::table('lead_activities', function (Blueprint $table) {
            $table->index(['tenant_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'created_at']);
            $table->dropIndex(['tenant_id', 'stage_entered_at']);
        });

        Schema::table('lead_activities', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'type', 'created_at']);
        });
    }
};
