<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the lead's way of paying came from: "chat" when it was read from the buyer's messages and an agent has
     * not confirmed it yet ("Buat resi" asks first); null when an agent, the order form or a confirmed order set it.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('payment_type_source', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('payment_type_source');
        });
    }
};
