<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;

/** Small settings the platform owner edits in /admin, kept as key => JSON. Not tenant-owned. */
class PlatformSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function read(string $key, array $default = []): array
    {
        return array_replace($default, (array) static::query()->where('key', $key)->value('value'));
    }

    public static function write(string $key, array $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public const ADDRESS_FULL = 'full';

    public const ADDRESS_CITY = 'city';

    public const ADDRESS_HIDDEN = 'hidden';

    /**
     * Business contact (what a payment gateway asks for). The address is always shown in full on /kontak and the legal
     * pages; `address_display` only decides what the front page shows: the whole address, the city, or nothing.
     *
     * @return array{email: ?string, phone: ?string, address: ?string, city: ?string, hours: ?string, address_display: string, show_email: bool, show_phone: bool}
     */
    public static function contact(): array
    {
        $c = static::read('contact', ['email' => null, 'phone' => null, 'address' => null, 'city' => null, 'hours' => null, 'show_email' => true, 'show_phone' => true]);

        // Older saves only had a show/hide switch for the address.
        $c['address_display'] = $c['address_display'] ?? (($c['show_address'] ?? true) ? self::ADDRESS_FULL : self::ADDRESS_HIDDEN);
        unset($c['show_address']);

        foreach (['show_email', 'show_phone'] as $flag) {
            $c[$flag] = (bool) $c[$flag];
        }

        return $c;
    }

    /**
     * Everything that is public on /kontak and in the legal pages, whatever the front-page switches say.
     *
     * @return array{email: ?string, phone: ?string, phone_digits: ?string, address: ?string, hours: ?string}
     */
    public static function fullContact(): array
    {
        try {
            $c = static::contact();
        } catch (\Throwable $e) {
            report($e);

            return ['email' => null, 'phone' => null, 'phone_digits' => null, 'address' => null, 'hours' => null];
        }

        return [
            'email' => filled($c['email']) ? trim($c['email']) : null,
            'phone' => filled($c['phone']) ? trim($c['phone']) : null,
            'phone_digits' => PhoneNumber::whatsapp($c['phone']),
            'address' => filled($c['address']) ? trim($c['address']) : null,
            'hours' => filled($c['hours']) ? trim($c['hours']) : null,
        ];
    }

    /**
     * The contact fields the owner chose to show on the front page. Never throws:
     * a missing table must not take the front page down.
     *
     * @return array{email?: string, phone?: string, phone_digits?: string, address?: string, address_label?: string}
     */
    public static function publicContact(): array
    {
        try {
            $c = static::contact();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $full = static::fullContact();
        $out = [];

        if ($c['show_email'] && $full['email']) {
            $out['email'] = $full['email'];
        }

        if ($c['show_phone'] && $full['phone']) {
            $out['phone'] = $full['phone'];

            if ($full['phone_digits']) {
                $out['phone_digits'] = $full['phone_digits'];
            }
        }

        if ($c['address_display'] === self::ADDRESS_FULL && $full['address']) {
            $out['address'] = $full['address'];
            $out['address_label'] = 'Alamat usaha';
        } elseif ($c['address_display'] === self::ADDRESS_CITY && filled($c['city'])) {
            $out['address'] = trim($c['city']);
            $out['address_label'] = 'Kota';
        }

        return $out;
    }
}
