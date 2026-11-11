<?php

namespace App\Tutorials;

use App\Models\Lead;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * While `php artisan tutorial:capture` takes screenshots (TUTORIAL_CAPTURE=1, local or testing only), the app talks
 * to no outside service: Everpro is answered here with made-up places, rates and balance, so "Buat resi" and the
 * shipping settings show what a connected shop sees, and every other outside call gets a 503, as if the service
 * were down. Nothing the demo shop does in the browser can reach Everpro, Meta, a WhatsApp gateway or the AI.
 */
class CaptureFakes
{
    public static function enabled(): bool
    {
        return (bool) config('jitu.tutorial_capture') && app()->environment(['local', 'testing']);
    }

    public static function register(): void
    {
        Http::fake([
            '*.everpro.id/*' => fn (Request $request) => self::everpro($request),
            '*' => Http::response(['message' => 'Tidak ada panggilan keluar saat mengambil gambar tutorial.'], 503),
        ]);
    }

    private static function everpro(Request $request)
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $ok = fn ($data) => Http::response(['data' => $data, 'status' => 'success', 'status_code' => 200]);

        return match (true) {
            $path === '/auth/v1/token' => $ok(['token' => 'demo-token', 'expires' => 86400, 'token_type' => 'Bearer']),
            $path === '/balance/v1/detail' => $ok(['cash' => 750000, 'name' => 'Toko Demo']),
            $path === '/location/v1/postalcode' => $ok(self::places((string) ($request->data()['postal_code'] ?? ''))),
            str_starts_with($path, '/location/v1/') => $ok(self::places('40132')),
            $path === '/shipment/v2/rates' => $ok(self::rates(($request->data()['is_cod'] ?? false) === true)),
            $path === '/shipment/v1/pickup-times' => $ok(['available_pickup_time' => []]),
            default => Http::response(['message' => 'Tidak tersedia di data demo.', 'data' => null], 404),
        };
    }

    /** The place of a postal code, read from the demo leads' own addresses. */
    private static function places(string $code): array
    {
        $parts = Lead::query()->where('shipping_address_parts->kode_pos', $code)->value('shipping_address_parts');
        $parts = is_array($parts) ? $parts : ($code === '40132' ? ['kelurahan' => 'Coblong', 'kecamatan' => 'Coblong', 'kota' => 'Kota Bandung', 'provinsi' => 'Jawa Barat'] : null);

        return $parts === null ? [] : [[
            'sub_district_id' => 'DEMO-'.$code, 'sub_district_name' => $parts['kelurahan'], 'district_name' => $parts['kecamatan'],
            'city_name' => $parts['kota'], 'province_name' => $parts['provinsi'], 'postal_code' => $code,
        ]];
    }

    private static function rates(bool $cod): array
    {
        $rate = fn (string $logistic, string $code, string $name, int $price, int $min, int $max) => [
            'signed_key' => 'demo-'.strtolower($code), 'name' => 'Everpro', 'logistic_name' => $logistic, 'logistic_logo_url' => '',
            'rate_code' => $code, 'rate_name' => $name, 'min_duration' => $min, 'max_duration' => $max, 'duration_type' => 'DAY',
            'price' => $price, 'shipment_price_after_discount' => $price, 'surcharge' => 0, 'insurance_price' => 1500, 'weight' => 1,
            'volume_weight' => 0, 'is_available_pickup_today' => true, 'cashback' => 0, 'is_flat_rate' => false, 'is_use_wood_packing' => false, 'logistic_code' => '',
        ];

        return [
            'regular' => array_values(array_filter([
                $rate('SiCepat', 'REG', 'Regular', 11000, 1, 2),
                $rate('JNE', 'REG23', 'Regular', 12000, 2, 3),
                $rate('J&T Express', 'EZ', 'Regular', 13000, 2, 3),
                $cod ? null : $rate('Lion Parcel', 'BOSSPACK', 'BOSSPACK', 10000, 2, 4),
            ])),
            'express' => [$rate('JNE', 'YES23', 'Yakin Esok Sampai', 24000, 1, 1)],
            'same_day' => [], 'instant' => [], 'cargo' => [], 'vehicle' => [],
        ];
    }
}
