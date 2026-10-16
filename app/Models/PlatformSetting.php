<?php

namespace App\Models;

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

    /**
     * Business contact (shown on the front page, and what a payment gateway asks for).
     *
     * @return array{email: ?string, phone: ?string, address: ?string, show_email: bool, show_phone: bool, show_address: bool}
     */
    public static function contact(): array
    {
        $c = static::read('contact', ['email' => null, 'phone' => null, 'address' => null, 'show_email' => true, 'show_phone' => true, 'show_address' => true]);

        foreach (['show_email', 'show_phone', 'show_address'] as $flag) {
            $c[$flag] = (bool) $c[$flag];
        }

        return $c;
    }

    /**
     * The contact fields the owner chose to publish, ready for the front page. Never throws:
     * a missing table must not take the front page down.
     *
     * @return array{email?: string, phone?: string, phone_digits?: string, address?: string}
     */
    public static function publicContact(): array
    {
        try {
            $c = static::contact();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $out = [];

        if ($c['show_email'] && filled($c['email'])) {
            $out['email'] = trim($c['email']);
        }

        if ($c['show_phone'] && filled($c['phone'])) {
            $out['phone'] = trim($c['phone']);
            $digits = preg_replace('/\D/', '', $c['phone']);
            // Indonesian numbers are written 08..., a link needs the country code.
            $out['phone_digits'] = str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits;
        }

        if ($c['show_address'] && filled($c['address'])) {
            $out['address'] = trim($c['address']);
        }

        return $out;
    }
}
