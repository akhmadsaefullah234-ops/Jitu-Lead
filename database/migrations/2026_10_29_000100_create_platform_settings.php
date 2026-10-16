<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        // Starting values so the Kontak section works right after deploying; the owner edits them in /admin.
        DB::table('platform_settings')->insert([
            'key' => 'contact',
            'value' => json_encode([
                'email' => 'akhmadsaefullah234@gmail.com',
                'phone' => '082113345882',
                'address' => 'Perum GCC2 Blok E13/21 Rt. 027/007 Kedungwaringin Kab. Bekasi',
                'show_email' => true,
                'show_phone' => true,
                'show_address' => true,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
