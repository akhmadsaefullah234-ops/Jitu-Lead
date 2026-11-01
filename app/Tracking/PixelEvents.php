<?php

namespace App\Tracking;

use App\Billing\PlanLimits;
use App\Models\TrackingSetting;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Every standard event of Meta, TikTok and Google, with a short Indonesian description, and the per-button /
 * per-page pixel settings built on them. A setting is stored as
 *
 *     {meta: "Lead"|"none"|"default"|"custom", meta_custom: "...", tiktok: ..., google: ..., send_to: "AW-1/abc",
 *      value: 150000, content_name: "..."}
 *
 * and resolved (resolve()) to what the page's script fires, only for platforms whose pixel id is filled in.
 */
class PixelEvents
{
    public const NONE = 'none';

    public const DEFAULT = 'default';

    public const CUSTOM = 'custom';

    public const CURRENCY = 'IDR';

    public const META = [
        'Lead' => 'calon pelanggan baru (isi formulir)',
        'AddPaymentInfo' => 'mengisi info pembayaran',
        'AddToCart' => 'menambah ke keranjang',
        'AddToWishlist' => 'menyimpan ke daftar keinginan',
        'CompleteRegistration' => 'pendaftaran selesai',
        'Contact' => 'menghubungi (WhatsApp, telepon, chat)',
        'CustomizeProduct' => 'memilih varian atau menyesuaikan produk',
        'Donate' => 'berdonasi',
        'FindLocation' => 'mencari lokasi toko atau kantor',
        'InitiateCheckout' => 'mulai checkout / pemesanan',
        'Purchase' => 'pembelian selesai',
        'Schedule' => 'menjadwalkan janji, misalnya survei',
        'Search' => 'mencari di situs',
        'StartTrial' => 'mulai masa coba',
        'SubmitApplication' => 'mengajukan permohonan, misalnya KPR',
        'Subscribe' => 'berlangganan berbayar',
        'ViewContent' => 'melihat halaman produk atau konten penting',
    ];

    /** Standard events first, then the older ones TikTok still accepts (ClickButton, CompletePayment, PlaceAnOrder, SubmitForm). */
    public const TIKTOK = [
        'SubmitForm' => 'kirim formulir',
        'AddPaymentInfo' => 'mengisi info pembayaran',
        'AddToCart' => 'menambah ke keranjang',
        'AddToWishlist' => 'menyimpan ke daftar keinginan',
        'ApplicationApproval' => 'permohonan disetujui',
        'ClickButton' => 'klik tombol',
        'CompletePayment' => 'pembayaran selesai',
        'CompleteRegistration' => 'pendaftaran selesai',
        'Contact' => 'menghubungi (WhatsApp, telepon, chat)',
        'CustomizeProduct' => 'memilih varian atau menyesuaikan produk',
        'Download' => 'mengunduh file atau aplikasi',
        'FindLocation' => 'mencari lokasi toko atau kantor',
        'InitiateCheckout' => 'mulai checkout / pemesanan',
        'Lead' => 'calon pelanggan baru',
        'PlaceAnOrder' => 'membuat pesanan',
        'Purchase' => 'pembelian selesai',
        'Schedule' => 'menjadwalkan janji',
        'Search' => 'mencari di situs',
        'StartTrial' => 'mulai masa coba',
        'SubmitApplication' => 'mengajukan permohonan',
        'Subscribe' => 'berlangganan',
        'ViewContent' => 'melihat halaman produk atau konten penting',
    ];

    public const GOOGLE = [
        'generate_lead' => 'calon pelanggan baru (isi formulir)',
        'sign_up' => 'mendaftar akun',
        'login' => 'masuk akun',
        'contact' => 'menghubungi (WhatsApp, telepon, chat)',
        'purchase' => 'pembelian selesai',
        'begin_checkout' => 'mulai checkout / pemesanan',
        'add_to_cart' => 'menambah ke keranjang',
        'add_payment_info' => 'mengisi info pembayaran',
        'add_shipping_info' => 'mengisi info pengiriman',
        'view_item' => 'melihat satu produk',
        'view_item_list' => 'melihat daftar produk',
        'select_item' => 'memilih produk dari daftar',
        'select_content' => 'memilih konten / klik tombol',
        'add_to_wishlist' => 'menyimpan ke daftar keinginan',
        'search' => 'mencari di situs',
        'share' => 'membagikan halaman',
        'view_promotion' => 'melihat promo',
        'select_promotion' => 'memilih promo',
    ];

    public const PLATFORMS = ['meta' => 'Meta', 'tiktok' => 'TikTok', 'google' => 'Google'];

    /** A custom event name: what each platform accepts for its own events. */
    public const CUSTOM_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,39}$/';

    /** @return array<string, string> event => description */
    public static function of(string $platform): array
    {
        return match ($platform) {
            'meta' => self::META, 'tiktok' => self::TIKTOK, 'google' => self::GOOGLE, default => [],
        };
    }

    /** @return array<string, string> event => "Event (keterangan)", for a select */
    public static function options(string $platform): array
    {
        $out = [];

        foreach (self::of($platform) as $name => $text) {
            $out[$name] = $name.' ('.$text.')';
        }

        return $out;
    }

    public static function isStandard(string $platform, ?string $event): bool
    {
        return $event !== null && array_key_exists($event, self::of($platform));
    }

    /**
     * Pixel settings for one button, form submit or page. $statePath is where they are stored; $defaultLabel names
     * what "Ikuti Pengaturan Pelacakan" means here, or null where there is no default (pages).
     */
    public static function fieldset(string $statePath, string $heading, ?string $defaultLabel): Fieldset
    {
        $choices = fn (string $platform) => ($defaultLabel !== null ? [self::DEFAULT => 'Ikuti Pengaturan Pelacakan ('.$defaultLabel.')'] : [])
            + [self::NONE => 'Tidak ada'] + self::options($platform) + [self::CUSTOM => 'Event kustom...'];

        $fields = [];

        foreach (self::PLATFORMS as $platform => $label) {
            $fields[] = Select::make($platform)->label($label)->options($choices($platform))->native(false)->searchable()
                ->default($defaultLabel !== null ? self::DEFAULT : self::NONE)->live();
            $fields[] = TextInput::make($platform.'_custom')->label('Nama event kustom '.$label)->maxLength(40)->regex(self::CUSTOM_PATTERN)
                ->validationMessages(['regex' => 'Huruf, angka, dan garis bawah saja, diawali huruf.'])
                ->visible(fn (Get $get) => $get($platform) === self::CUSTOM)->required(fn (Get $get) => $get($platform) === self::CUSTOM);
        }

        return Fieldset::make($heading)->statePath($statePath)->columns(['default' => 1, 'sm' => 2])
            ->visible(fn () => PlanLimits::current()?->feature('pixels') ?? true)
            ->schema([
                ...$fields,
                TextInput::make('send_to')->label('Label konversi Google Ads (opsional)')->maxLength(80)->regex('/^(AW-\d{4,20}\/)?[A-Za-z0-9_-]{4,60}$/')
                    ->placeholder('AW-123456789/AbCdEf atau AbCdEf')->helperText('Hanya bila ID tag Google Anda diawali AW-.'),
                TextInput::make('value')->label('Nilai (Rupiah, opsional)')->numeric()->minValue(0)->maxValue(100000000000),
                TextInput::make('content_name')->label('Nama konten (opsional)')->maxLength(100)->placeholder('mis. Rumah Tipe 36'),
            ]);
    }

    /**
     * What the browser fires for these settings, or null when nothing would fire. Platforms without a pixel id
     * are left out. $defaults are the event names "Ikuti Pengaturan Pelacakan" stands for, per platform.
     *
     * @param  array<string, string|null>  $defaults
     * @return array{meta?: array{0: string, 1: bool}, tiktok?: string, google?: string, send_to?: string, value?: float, currency?: string, content_name?: string}|null
     */
    public static function resolve(?array $config, array $defaults, ?TrackingSetting $tracking, ?string $defaultSendTo = null): ?array
    {
        if ($tracking === null || ! $tracking->hasAny()) {
            return null;
        }

        $config = (array) $config;
        $out = [];

        foreach (array_keys(self::PLATFORMS) as $platform) {
            $id = match ($platform) {
                'meta' => $tracking->meta_pixel_id, 'tiktok' => $tracking->tiktok_pixel_id, default => $tracking->google_tag_id
            };

            if (blank($id)) {
                continue;
            }

            $name = self::eventName($platform, $config, $defaults[$platform] ?? null);

            if ($name !== null) {
                $out[$platform] = $platform === 'meta' ? [$name, ! self::isStandard('meta', $name)] : $name;
            }
        }

        $sendTo = self::sendTo(filled($config['send_to'] ?? null) ? $config['send_to'] : (($config['google'] ?? self::DEFAULT) === self::DEFAULT ? $defaultSendTo : null), $tracking);

        if ($sendTo !== null) {
            $out['send_to'] = $sendTo;
        }

        if ($out === []) {
            return null;
        }

        if (is_numeric($config['value'] ?? null) && (float) $config['value'] >= 0) {
            $out['value'] = (float) $config['value'];
            $out['currency'] = self::CURRENCY;
        }

        if (filled($config['content_name'] ?? null)) {
            $out['content_name'] = mb_substr(trim((string) $config['content_name']), 0, 100);
        }

        return $out;
    }

    /** The event a setting stands for on one platform, or null for none. */
    public static function eventName(string $platform, array $config, ?string $default): ?string
    {
        $choice = $config[$platform] ?? self::DEFAULT;

        $name = match (true) {
            $choice === self::NONE => null,
            $choice === self::DEFAULT => $default,
            $choice === self::CUSTOM => $config[$platform.'_custom'] ?? null,
            default => $choice,
        };

        if (! is_string($name) || $name === '' || $name === self::NONE) {
            return null;
        }

        return self::isStandard($platform, $name) || preg_match(self::CUSTOM_PATTERN, $name) ? $name : null;
    }

    /** "AW-1/abc", or a bare label joined to the agency's AW- tag. Null without an Ads tag. */
    private static function sendTo(?string $value, TrackingSetting $tracking): ?string
    {
        $tag = (string) $tracking->google_tag_id;
        $value = trim((string) $value);

        if (! str_starts_with($tag, 'AW-') || $value === '') {
            return null;
        }

        if (preg_match('#^AW-\d{4,20}/[A-Za-z0-9_-]{4,60}$#', $value)) {
            return $value;
        }

        return preg_match('/^[A-Za-z0-9_-]{4,60}$/', $value) ? $tag.'/'.$value : null;
    }
}
