<?php

use App\LandingPages\Navigation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The header button, the bottom bar and the floating WhatsApp button become settings of each landing page. Pages
 * that exist get them switched off, like new pages; the owner can turn them back on in "Tombol & navigasi".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            $table->json('navigation')->nullable();
        });

        DB::table('landing_pages')->update(['navigation' => json_encode(Navigation::defaults())]);
    }

    public function down(): void
    {
        Schema::table('landing_pages', fn (Blueprint $table) => $table->dropColumn('navigation'));
    }
};
