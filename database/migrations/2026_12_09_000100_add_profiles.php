<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profil saya and Profil agensi: a user's photo, and the agency's public identity (logo, colour, business WhatsApp,
 * contact e-mail, address, "tentang kami", website) in one json column. Both nullable: nothing changes until filled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('avatar_path', 255)->nullable());
        Schema::table('tenants', fn (Blueprint $table) => $table->json('profile')->nullable());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('avatar_path'));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('profile'));
    }
};
