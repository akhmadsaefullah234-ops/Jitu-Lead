<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "Menunggu saldo": a parcel held because the provider's balance does not cover its shipping, tried again once it does. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->timestamp('balance_wait_at')->nullable()->after('error');
            $table->unsignedInteger('balance_needed')->nullable()->after('balance_wait_at');
            $table->index(['balance_wait_at']);
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['balance_wait_at']);
            $table->dropColumn(['balance_wait_at', 'balance_needed']);
        });
    }
};
