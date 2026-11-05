<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Automatic shipping through a courier aggregator (App\Shipping\Carriers, Everpro first): one account per agency
 * (credentials encrypted, a callback token for its own URL), one row per parcel with its tracking history, and
 * one row per buyer message about a parcel status, so each status reaches the buyer at most once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->boolean('sandbox')->default(true);
            $table->text('credentials')->nullable();
            $table->string('callback_token', 64)->unique();
            $table->json('settings')->nullable();
            $table->json('messages')->nullable();
            $table->bigInteger('balance')->nullable();
            $table->timestamp('balance_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('client_order_no', 60)->unique();
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->string('order_id', 80)->nullable();
            $table->string('awb', 60)->nullable()->index();
            $table->string('courier', 60)->nullable();
            $table->string('rate_code', 60)->nullable();
            $table->string('state', 12)->default('pending');
            $table->string('status', 20)->nullable();
            $table->string('raw_status', 60)->nullable();
            $table->string('status_reason')->nullable();
            $table->boolean('is_cod')->default(false);
            $table->unsignedBigInteger('cod_amount')->nullable();
            $table->unsignedBigInteger('price')->nullable();
            $table->unsignedBigInteger('charged')->nullable();
            $table->unsignedInteger('weight_grams')->nullable();
            $table->string('label_url', 500)->nullable();
            $table->json('request')->nullable();
            $table->json('events')->nullable();
            $table->string('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('awb_notified_at')->nullable();
            $table->timestamp('tracked_at')->nullable();
            $table->timestamp('final_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'lead_id']);
            $table->index(['state', 'final_at']);
        });

        Schema::create('shipment_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->string('state', 10)->default('pending');
            $table->timestamp('event_at');
            $table->timestamp('due_at');
            $table->string('note')->nullable();
            $table->foreignId('wa_message_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['shipment_id', 'status']);
            $table->index(['state', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_notices');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('carrier_accounts');
    }
};
