<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 3, pembayaran otomatis untuk produk digital: akun iPaymu per agensi (kunci terenkripsi), invoice per
 * pembelian, dan tautan akses produk. Hanya menambah tabel dan kolom nullable; langganan iPaymu CRM (ipaymu_payments)
 * dan tenant lain tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('ipaymu');
            // Encrypted with APP_KEY (casts); never logged, never shown back in the form.
            $table->text('va')->nullable();
            $table->text('api_key')->nullable();
            $table->boolean('sandbox')->default(true);
            $table->boolean('active')->default(false);
            $table->unsignedSmallInteger('invoice_hours')->default(24);
            $table->unsignedSmallInteger('access_days')->default(7);
            $table->timestamps();
        });

        Schema::create('digital_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('reference_id', 60)->unique();
            // The buyer's own page (/bayar/{token}): unguessable, no login.
            $table->string('token', 64)->unique();
            $table->string('session_id', 120)->nullable();
            $table->string('payment_url', 500)->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('status', 20)->default('pending');
            $table->string('trx_id', 60)->nullable();
            $table->string('channel', 60)->nullable();
            $table->unsignedBigInteger('fee')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('last_callback_at')->nullable();
            $table->timestamp('access_opened_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['lead_id', 'created_at']);
        });

        Schema::table('properties', function (Blueprint $table) {
            // Digital products: where the paid buyer is sent (download, class, group). Never given to the AI.
            $table->string('access_url', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('access_url');
        });

        Schema::dropIfExists('digital_payments');
        Schema::dropIfExists('payment_gateways');
    }
};
