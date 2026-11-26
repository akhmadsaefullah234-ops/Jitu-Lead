<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up harian paket gagal antar (App\Shipping\Shipments\ParcelFollowUps): setelan per agensi (tenants.parcel_follow_up,
 * kosong = mati) dan satu baris per kiriman yang mencatat siklus gagal antar, jumlah pesan, dan alasan berhenti.
 * Hanya menambah tabel dan kolom nullable; tidak ada yang menyala sampai admin mengaktifkannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('parcel_follow_up')->nullable();
        });

        Schema::create('parcel_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            // The courier status of the current cycle (delivery_failed or returning) and when it started.
            $table->string('status', 30);
            $table->timestamp('failed_at');
            $table->unsignedSmallInteger('cycle')->default(1);
            // Messages sent for this parcel, every cycle together (the limit counts these).
            $table->unsignedSmallInteger('sent_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            // The agency's local day of the last try (sent or not): one try per day, safe when the scheduler runs twice.
            $table->string('last_attempt_on', 10)->nullable();
            $table->string('state', 20)->default('active');
            $table->string('stop_reason', 120)->nullable();
            $table->timestamp('stopped_at')->nullable();
            // "Perlu tindakan" on the chat in the Inbox until someone answers from there.
            $table->timestamp('attention_at')->nullable();
            $table->string('attention_reason', 120)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'state']);
            $table->index(['tenant_id', 'lead_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_follow_ups');
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('parcel_follow_up'));
    }
};
