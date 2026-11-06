<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The "Pengiriman" menu and automatic resi: each parcel keeps its label PDF (labels/{tenant}/{date}/{awb}.pdf),
 * when and by whom it was printed, and whether it was booked automatically. A lead carries its automatic resi:
 * when it will be made, its state and why it was not. Each print run is kept to download again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('label_path', 300)->nullable();
            $table->timestamp('label_saved_at')->nullable();
            $table->unsignedSmallInteger('label_attempts')->default(0);
            $table->timestamp('label_failed_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('print_count')->default(0);
            $table->boolean('automatic')->default(false);
            $table->index(['tenant_id', 'printed_at']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('auto_shipment_at')->nullable();
            $table->string('auto_shipment_state', 12)->nullable();
            $table->string('auto_shipment_note', 500)->nullable();
            $table->index(['auto_shipment_state', 'auto_shipment_at']);
        });

        Schema::create('label_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('count');
            $table->boolean('packing_list')->default(false);
            $table->string('file', 60);
            $table->json('shipment_ids');
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_batches');

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['auto_shipment_state', 'auto_shipment_at']);
            $table->dropColumn(['auto_shipment_at', 'auto_shipment_state', 'auto_shipment_note']);
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'printed_at']);
            $table->dropConstrainedForeignId('printed_by');
            $table->dropColumn(['label_path', 'label_saved_at', 'label_attempts', 'label_failed_at', 'printed_at', 'print_count', 'automatic']);
        });
    }
};
