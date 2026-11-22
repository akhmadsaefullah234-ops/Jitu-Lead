<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 1 pembayaran transfer: rekening bank per tenant (Pengaturan > Pembayaran) dan instruksi bayar yang disusun
 * sistem (App\Payments\PaymentInstructions). Hanya menambah tabel dan kolom; tabel lama tidak diubah. Tenant tanpa
 * rekening aktif tetap berperilaku seperti sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name', 60);
            $table->string('account_number', 40);
            $table->string('account_holder', 120);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'active']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            // {batas_waktu_bayar, kode_unik, semua_rekening, template_instruksi_bayar} (App\Payments\PaymentSettings). Null = bawaan.
            $table->json('payment_settings')->nullable();
        });

        Schema::table('leads', function (Blueprint $table) {
            // Kode unik 3 digit dan nominal transfer yang diminta (total + kode unik). deal_value tetap total pesanan.
            $table->unsignedSmallInteger('payment_unique_code')->nullable();
            $table->unsignedBigInteger('payment_amount')->nullable();
            $table->timestamp('payment_due_at')->nullable();
            $table->timestamp('payment_instruction_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['payment_unique_code', 'payment_amount', 'payment_due_at', 'payment_instruction_sent_at']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('payment_settings');
        });

        Schema::dropIfExists('payment_accounts');
    }
};
