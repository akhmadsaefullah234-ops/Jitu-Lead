<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('stage_entered_at')->nullable();
        });

        Schema::create('follow_up_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('stage_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('delay_hours');
            $table->text('body');
            $table->unsignedTinyInteger('send_from_hour')->default(8);
            $table->unsignedTinyInteger('send_until_hour')->default(20);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'active']);
        });

        Schema::create('follow_up_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('follow_up_rule_id')->constrained('follow_up_rules')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stage_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['follow_up_rule_id', 'lead_id', 'stage_id']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_logs');
        Schema::dropIfExists('follow_up_rules');
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('stage_entered_at');
        });
    }
};
