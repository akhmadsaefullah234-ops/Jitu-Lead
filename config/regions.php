<?php

/*
 * Where "php artisan regions:import" reads Indonesia's administrative regions from. Both are open datasets under the
 * MIT license that follow the latest Kepmendagri on region codes; see docs/regions.md. A local file path works too.
 */
return [
    'sources' => [
        'wilayah' => env('REGIONS_WILAYAH_URL', 'https://raw.githubusercontent.com/cahyadsn/wilayah/master/db/wilayah.sql'),
        'kodepos' => env('REGIONS_KODEPOS_URL', 'https://raw.githubusercontent.com/cahyadsn/wilayah_kodepos/main/db/wilayah_kodepos.sql'),
    ],
];
