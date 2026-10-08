<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('password');
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('plan')->default('trial');
            $table->string('status')->default('trial');
            $table->string('timezone')->default('Asia/Jakarta');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('position');
            $table->string('type')->default('open');
            $table->string('requirement')->nullable();
            $table->string('default_action')->nullable();
            $table->unsignedInteger('default_due_hours')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'position']);
        });

        Schema::create('lost_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('manual');
            $table->timestamps();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('name');
            $table->string('property_type')->nullable();
            $table->string('location')->nullable();
            $table->string('developer')->nullable();
            $table->unsignedBigInteger('price_from')->nullable();
            $table->string('status')->default('available');
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'kind']);
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->foreignId('lead_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stage_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('interest')->default('warm');
            $table->string('next_action')->nullable();
            $table->timestamp('next_action_due_at')->nullable();
            $table->string('need')->default('buy');
            $table->string('property_type')->nullable();
            $table->string('location')->nullable();
            $table->unsignedBigInteger('budget_min')->nullable();
            $table->unsignedBigInteger('budget_max')->nullable();
            $table->string('payment_method')->nullable();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('survey_at')->nullable();
            $table->string('survey_location')->nullable();
            $table->string('unit')->nullable();
            $table->unsignedBigInteger('deal_value')->nullable();
            $table->foreignId('lost_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->json('custom_fields')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'stage_id']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'next_action_due_at']);
        });

        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('properties');
        Schema::dropIfExists('lead_sources');
        Schema::dropIfExists('lost_reasons');
        Schema::dropIfExists('stages');
        Schema::dropIfExists('tenant_user');
        Schema::dropIfExists('tenants');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_super_admin');
        });
    }
};
