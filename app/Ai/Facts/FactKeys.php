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
        // The payment model, from the wizard only (App\Ai\Facts\PaymentModel); never read from a document.
        'pay_options' => ['Cara bayar yang tersedia', self::TEXT],
        'installment_tenor' => ['Tenor cash bertahap maksimal (bulan)', self::COUNT],
        'installment_interest' => ['Bunga cash bertahap', self::TEXT],
        'developer_tenor' => ['Tenor cicilan developer maksimal (bulan)', self::COUNT],
        'kpr_banks' => ['Bank rekanan KPR', self::TEXT],
        'pay_other' => ['Cara bayar lainnya', self::TEXT],
        'dp_model' => ['Model uang muka', self::TEXT],
        'dp_amount' => ['Besar uang muka', self::TEXT],
        'dp_promo' => ['Periode promo uang muka', self::TEXT],
        'booking_fee' => ['Tanda jadi (booking fee)', self::MONEY],
        'booking_fee_reduces' => ['Tanda jadi mengurangi harga', self::TEXT],
        'payment_notes' => ['Catatan pembayaran', self::TEXT],
        // Free text from before the payment model, and what a document says about payment.
        'payment_methods' => ['Cara bayar (catatan)', self::TEXT],
        'down_payment' => ['Uang muka (DP) (catatan)', self::TEXT],
        'tenor' => ['Tenor cicilan (catatan)', self::TEXT],
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

    /** Keys of the payment model, set only in the wizard. */
    public const PAYMENT = ['pay_options', 'installment_tenor', 'installment_interest', 'developer_tenor', 'kpr_banks', 'pay_other', 'dp_model', 'dp_amount', 'dp_promo', 'booking_fee_reduces', 'payment_notes'];

    /** @return array<string, array{0: string, 1: string}> the project facts a document may be read into */
    public static function fromDocuments(): array
    {
        return array_diff_key(self::PROJECT, array_flip(self::PAYMENT));
    }

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
