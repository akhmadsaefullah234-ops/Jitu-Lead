<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_messages', function (Blueprint $table) {
            // An outgoing message an agent typed in the WhatsApp app on the phone (gateway number), not in the CRM.
            $table->boolean('from_phone')->default(false);
        });

        Schema::table('tenants', function (Blueprint $table) {
            // Off for agencies that do not sell property: the property fields leave the lead form and list.
            $table->boolean('show_property_fields')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('show_property_fields'));
        Schema::table('wa_messages', fn (Blueprint $table) => $table->dropColumn('from_phone'));
    }
};
