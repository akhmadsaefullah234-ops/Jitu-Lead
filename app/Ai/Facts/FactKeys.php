<?php

namespace App\Ai\Facts;

/**
 * The facts a property agency's AI needs, as the "Isi properti" wizard asks them and as a document is read into them.
 * Project facts have no subject; unit type facts belong to one type ("Melati"). Each has a kind that says how it is
 * stored, compared and written for the AI.
 */
final class FactKeys
{
    public const MONEY = 'money';

    public const AREA = 'area';

    public const COUNT = 'count';

    public const TEXT = 'text';

    /** @var array<string, array{0: string, 1: string}> key => [label, kind] */
    public const PROJECT = [
        'project' => ['Nama proyek', self::TEXT],
        'developer' => ['Developer', self::TEXT],
        'location' => ['Lokasi', self::TEXT],
        'legal' => ['Legalitas', self::TEXT],
        'handover' => ['Serah terima', self::TEXT],
        'payment_methods' => ['Cara bayar', self::TEXT],
        'booking_fee' => ['Booking fee', self::MONEY],
        'down_payment' => ['Uang muka (DP)', self::TEXT],
        'tenor' => ['Tenor cicilan', self::TEXT],
        'rent_estimate' => ['Perkiraan sewa', self::TEXT],
        'survey_hours' => ['Jam survei', self::TEXT],
        'survey_location' => ['Lokasi survei', self::TEXT],
        'contact' => ['Kontak', self::TEXT],
    ];

    /** @var array<string, array{0: string, 1: string}> */
    public const TYPE = [
        'price' => ['Harga', self::MONEY],
        'building_area' => ['Luas bangunan', self::AREA],
        'land_area' => ['Luas tanah', self::AREA],
        'bedrooms' => ['Kamar tidur', self::COUNT],
        'bathrooms' => ['Kamar mandi', self::COUNT],
        'availability' => ['Ketersediaan', self::TEXT],
        'bonus' => ['Bonus', self::TEXT],
    ];

    public const AVAILABILITY = ['Tersedia' => 'Tersedia', 'Inden' => 'Inden (belum dibangun)', 'Terjual' => 'Terjual habis'];

    public static function label(string $key): string
    {
        return (self::PROJECT[$key] ?? self::TYPE[$key] ?? [$key])[0];
    }

    public static function kind(string $key): string
    {
        return (self::PROJECT[$key] ?? self::TYPE[$key] ?? [null, self::TEXT])[1];
    }

    public static function known(string $key, bool $type): bool
    {
        return array_key_exists($key, $type ? self::TYPE : self::PROJECT);
    }
}
