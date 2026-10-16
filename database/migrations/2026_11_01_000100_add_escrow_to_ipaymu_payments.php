<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ipaymu_payments', function (Blueprint $table) {
            // iPaymu holds the money of an escrow transaction; the buyer has paid, so the plan is activated.
            $table->boolean('is_escrow')->default(false)->after('paid_off');
        });
    }

    public function down(): void
    {
        Schema::table('ipaymu_payments', function (Blueprint $table) {
            $table->dropColumn('is_escrow');
        });
    }
};
