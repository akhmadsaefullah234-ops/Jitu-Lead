<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The provider's own order number (Everpro prepaid books as "TRD…" whatever client_order_no was sent) and what it
 * charges and pays out per parcel: COD fee, COD paid out, cashback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('provider_order_no', 60)->nullable()->after('order_id');
            $table->integer('cod_fee')->nullable()->after('cod_amount');
            $table->integer('cod_disbursement')->nullable()->after('cod_fee');
            $table->integer('cashback')->nullable()->after('cod_disbursement');
            $table->index(['tenant_id', 'provider_order_no']);
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'provider_order_no']);
            $table->dropColumn(['provider_order_no', 'cod_fee', 'cod_disbursement', 'cashback']);
        });
    }
};
