<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The four payment profiles of the property demo: Agensi Demo (KPR and a light down payment) and the three agencies
 * below, each filled only through "Isi properti".
 *
 *   php artisan db:seed --class=AgensiDemoPembayaranSeeder
 */
class AgensiDemoPembayaranSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([AgensiDemoSeeder::class, AgensiDemoBertahapSeeder::class, AgensiDemoDpNolSeeder::class, AgensiDemoTandaJadiSeeder::class]);
    }
}
