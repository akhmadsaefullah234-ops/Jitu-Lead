<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akses unduhan produk digital yang aman: berkas privat per produk (disk "digital", tidak pernah publik), dan satu
 * tautan akses per pembeli (akses/{token}) dengan masa berlaku, batas unduhan, jejak buka dan unduh, kirim ulang,
 * dan pencabutan. Hanya menambah tabel dan kolom nullable; tenant lain tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Path on the private "digital" disk; the original name is what the buyer downloads.
            $table->string('access_file_path', 255)->nullable();
            $table->string('access_file_name', 255)->nullable();
            $table->unsignedBigInteger('access_file_size')->nullable();
            // Null = the default (App\Digital\DigitalAccesses::DEFAULT_DAYS / DEFAULT_DOWNLOADS).
            $table->unsignedSmallInteger('access_days')->nullable();
            $table->unsignedSmallInteger('access_max_downloads')->nullable();
        });

        Schema::create('digital_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('digital_payment_id')->nullable()->constrained()->nullOnDelete();
            // The buyer's link (/akses/{token}, signed): unguessable, never written to activity or shown to the AI.
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('max_downloads');
            $table->unsignedInteger('downloads')->default(0);
            $table->unsignedSmallInteger('resends')->default(0);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('last_downloaded_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->string('last_user_agent', 255)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 120)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'lead_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_accesses');

        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['access_file_path', 'access_file_name', 'access_file_size', 'access_days', 'access_max_downloads']);
        });
    }
};
