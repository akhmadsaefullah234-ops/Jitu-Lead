<?php

use App\Automation\RetailStages;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic stage moves for shops: each retail stage remembers which step of the template it is (template_key),
 * so the rules keep working after a stage is renamed; leads get the payment, proof and shipment details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stages', function (Blueprint $table) {
            $table->string('template_key', 30)->nullable()->after('name');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->string('payment_type', 20)->nullable();
            $table->timestamp('payment_proof_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('shipping_province', 60)->nullable();
            $table->string('tracking_number', 60)->nullable();
            $table->timestamp('shipped_at')->nullable();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->json('stage_automation')->nullable();
        });

        foreach (DB::table('tenants')->where('industry', 'retail')->pluck('id') as $tenantId) {
            $stages = DB::table('stages')->where('tenant_id', $tenantId)->orderBy('position')->orderBy('id')->get(['id', 'name', 'type'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'type' => $s->type])->all();

            foreach (RetailStages::match($stages) as $key => $id) {
                DB::table('stages')->where('id', $id)->update(['template_key' => $key]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('stage_automation'));
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn(['payment_type', 'payment_proof_at', 'paid_at', 'shipping_province', 'tracking_number', 'shipped_at']));
        Schema::table('stages', fn (Blueprint $table) => $table->dropColumn('template_key'));
    }
};
