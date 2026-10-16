<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ipaymu_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reference_id')->unique();
            $table->string('session_id')->nullable()->index();
            $table->string('trx_id')->nullable()->index();
            $table->text('payment_url')->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('status')->default('pending');
            $table->string('channel')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('last_callback_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ipaymu_payments');
    }
};
