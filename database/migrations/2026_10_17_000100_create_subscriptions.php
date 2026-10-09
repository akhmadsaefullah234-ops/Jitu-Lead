<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('plan')->nullable();
            $table->string('billing_cycle')->default('monthly');
            $table->string('status')->default('trial');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->unsignedInteger('addon_ai')->default(0);
            $table->unsignedInteger('addon_user')->default(0);
            $table->unsignedInteger('addon_wa')->default(0);
            $table->timestamp('reminder_sent_for')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('plan');
            $table->string('billing_cycle');
            $table->unsignedBigInteger('amount');
            $table->string('status')->default('pending');
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('ai_drafts', function (Blueprint $table) {
            // True when this run called the AI model, so it counts against the plan's quota.
            $table->boolean('api_call')->default(false);
        });

        // Agencies that already exist get a 14 day trial counted from now.
        $now = now();
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::table('subscriptions')->insert([
                'tenant_id' => $tenantId, 'plan' => null, 'billing_cycle' => 'monthly', 'status' => 'trial',
                'trial_ends_at' => $now->copy()->addDays(14), 'current_period_start' => $now, 'current_period_end' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Earlier AI answers each cost one model call; count them toward the current period.
        DB::table('ai_drafts')->where('status', '!=', 'handoff')->update(['api_call' => true]);
    }

    public function down(): void
    {
        Schema::table('ai_drafts', function (Blueprint $table) {
            $table->dropColumn('api_call');
        });
        Schema::dropIfExists('subscription_requests');
        Schema::dropIfExists('subscriptions');
    }
};
