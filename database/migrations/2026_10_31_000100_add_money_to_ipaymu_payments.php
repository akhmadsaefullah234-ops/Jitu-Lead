<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ipaymu_payments', function (Blueprint $table) {
            // amount stays the plan price; these are what iPaymu reported (the buyer may have been charged a fee on top).
            $table->unsignedBigInteger('total_paid')->nullable()->after('amount');
            $table->unsignedBigInteger('fee')->nullable()->after('total_paid');
            $table->unsignedBigInteger('paid_off')->nullable()->after('fee');
        });
    }

    public function down(): void
    {
        Schema::table('ipaymu_payments', function (Blueprint $table) {
            $table->dropColumn(['total_paid', 'fee', 'paid_off']);
        });
    }
};
