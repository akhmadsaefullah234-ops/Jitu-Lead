<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A follow-up rule can belong to one product; empty means it is for every product (the general sequence).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_up_rules', function (Blueprint $table) {
            $table->foreignId('property_id')->nullable()->after('stage_id')->constrained()->cascadeOnDelete();
            $table->index(['tenant_id', 'property_id', 'stage_id']);
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_rules', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'property_id', 'stage_id']);
            $table->dropConstrainedForeignId('property_id');
        });
    }
};
