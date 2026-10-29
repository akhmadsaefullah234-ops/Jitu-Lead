<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // The kind of business (config/industries.php); every existing agency sells property.
            $table->string('industry', 30)->default('properti');
        });

        Schema::table('leads', function (Blueprint $table) {
            // Order details for retail agencies
            $table->string('variant', 120)->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->text('shipping_address')->nullable();
            $table->string('courier', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn(['variant', 'quantity', 'shipping_address', 'courier']));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('industry'));
    }
};
