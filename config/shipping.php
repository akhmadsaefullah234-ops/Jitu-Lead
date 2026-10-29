<?php

use App\Shipping\ZoneShippingRates;

/*
 * Shipping cost for retail agencies (tenants.shipping, edited under Pengaturan > Pengiriman).
 *
 * "providers" maps the provider an agency uses to the class that quotes it (App\Shipping\ShippingProvider).
 * Only "zone" exists now: per-kg rates per zone that the agency types in itself. A courier API
 * (KiriminAja, JNE) becomes another entry here; the AI tools and the inbox button do not change.
 *
 * "presets" are starting settings filled in for a known agency by slug (see the migration that adds them).
 */
return [

    'providers' => [
        'zone' => ZoneShippingRates::class,
    ],

    // Used when a product in the catalogue has no weight yet
    'default_weight_grams' => 1000,

    // Volume weight = length x width x height (cm) / this
    'volumetric_divisor' => 6000,

    'presets' => [
        'quicker-sport' => [
            'enabled' => true,
            'provider' => 'zone',
            'origin' => 'Kedungwaringin, Kab. Bekasi, Jawa Barat',
            'couriers' => ['JNE REG', 'SiCepat REG'],
            'default_weight_grams' => 1000,
            'volumetric_divisor' => 6000,
            'zones' => [
                [
                    'name' => 'Jabodetabek',
                    'provinces' => ['DKI Jakarta'],
                    'cities' => ['Bogor', 'Depok', 'Bekasi', 'Tangerang', 'Tangerang Selatan', 'Cikarang', 'Cibinong', 'Cileungsi', 'Tambun', 'Serpong', 'Ciputat', 'Pamulang'],
                    'excluded_cities' => [],
                    'rates' => [['courier' => 'JNE REG', 'per_kg' => 11000], ['courier' => 'SiCepat REG', 'per_kg' => 10000]],
                    'eta_min' => 1, 'eta_max' => 3, 'confirm_admin' => false,
                ],
                [
                    'name' => 'Jawa Barat lain & Banten',
                    'provinces' => ['Jawa Barat', 'Banten'],
                    'cities' => [],
                    'excluded_cities' => ['Bogor', 'Depok', 'Bekasi', 'Tangerang', 'Tangerang Selatan', 'Cikarang', 'Cibinong', 'Cileungsi', 'Tambun', 'Serpong', 'Ciputat', 'Pamulang'],
                    'rates' => [['courier' => 'JNE REG', 'per_kg' => 14000], ['courier' => 'SiCepat REG', 'per_kg' => 13000]],
                    'eta_min' => 1, 'eta_max' => 3, 'confirm_admin' => false,
                ],
                [
                    'name' => 'Jawa Tengah & DIY',
                    'provinces' => ['Jawa Tengah', 'DI Yogyakarta'],
                    'cities' => [], 'excluded_cities' => [],
                    'rates' => [['courier' => 'JNE REG', 'per_kg' => 21000], ['courier' => 'SiCepat REG', 'per_kg' => 19000]],
                    'eta_min' => 2, 'eta_max' => 4, 'confirm_admin' => false,
                ],
                [
                    'name' => 'Jawa Timur',
                    'provinces' => ['Jawa Timur'],
                    'cities' => [], 'excluded_cities' => [],
                    'rates' => [['courier' => 'JNE REG', 'per_kg' => 23000], ['courier' => 'SiCepat REG', 'per_kg' => 21000]],
                    'eta_min' => 2, 'eta_max' => 4, 'confirm_admin' => false,
                ],
                [
                    'name' => 'Bali',
                    'provinces' => ['Bali'],
                    'cities' => [], 'excluded_cities' => [],
                    'rates' => [['courier' => 'JNE REG', 'per_kg' => 33000], ['courier' => 'SiCepat REG', 'per_kg' => 30000]],
                    'eta_min' => 2, 'eta_max' => 6, 'confirm_admin' => false,
                ],
                [
                    'name' => 'Sumatera',
                    'provinces' => ['Aceh', 'Sumatera Utara', 'Sumatera Barat', 'Riau', 'Kepulauan Riau', 'Jambi', 'Sumatera Selatan', 'Kepulauan Bangka Belitung', 'Bengkulu', 'Lampung'],
                    'cities' => [], 'excluded_cities' => [],
                    'rates' => [['courier' => 'JNE REG', 'per_kg' => 52000], ['courier' => 'SiCepat REG', 'per_kg' => 45000]],
                    'eta_min' => 2, 'eta_max' => 6, 'confirm_admin' => false,
                ],
                [
                    'name' => 'Kalimantan',
                    'provinces' => ['Kalimantan Barat', 'Kalimantan Tengah', 'Kalimantan Selatan', 'Kalimantan Timur', 'Kalimantan Utara'],
                    'cities' => [], 'excluded_cities' => [],
                    'rates' => [['courier' => 'JNE REG', 'per_kg' => 68000], ['courier' => 'SiCepat REG', 'per_kg' => 58000]],
                    'eta_min' => 2, 'eta_max' => 5, 'confirm_admin' => false,
                ],
                [
                    'name' => 'Sulawesi',
                    'provinces' => ['Sulawesi Utara', 'Gorontalo', 'Sulawesi Tengah', 'Sulawesi Barat', 'Sulawesi Selatan', 'Sulawesi Tenggara'],
                    'cities' => [], 'excluded_cities' => [],
                    'rates' => [['courier' => 'JNE REG', 'per_kg' => 62000], ['courier' => 'SiCepat REG', 'per_kg' => 80000]],
                    'eta_min' => 3, 'eta_max' => 6, 'confirm_admin' => false,
                ],
                [
                    'name' => 'NTB, NTT, Maluku, Papua',
                    'provinces' => ['Nusa Tenggara Barat', 'Nusa Tenggara Timur', 'Maluku', 'Maluku Utara', 'Papua', 'Papua Barat', 'Papua Barat Daya', 'Papua Tengah', 'Papua Pegunungan', 'Papua Selatan'],
                    'cities' => [], 'excluded_cities' => [],
                    'rates' => [],
                    'eta_min' => null, 'eta_max' => null, 'confirm_admin' => true,
                ],
            ],
        ],
    ],
];
