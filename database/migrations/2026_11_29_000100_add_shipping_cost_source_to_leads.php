<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Where a recorded order's shipping cost came from ("zone" table or "everpro" rates), so "Buat resi" can name a difference. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('shipping_cost_source', 20)->nullable()->after('shipping_cost');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('shipping_cost_source');
        });
    }
};
