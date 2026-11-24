<?php

namespace App\Agency;

use App\LandingPages\Embeds;
use App\Models\Tenant;
use App\Support\PhoneNumber;

/**
 * The agency's public identity, set by an admin on Pengaturan > Profil agensi (tenants.profile, with the name and
 * time zone in their own columns). The one place landing pages (logo, colour, WhatsApp, footer) and message
 * templates ({agensi}, {wa_agensi}) read it from. Every value is optional: an empty one leaves the old behaviour.
 */
final class AgencyProfile
{
    public const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    public const TIMEZONES = [
        'Asia/Jakarta' => 'WIB (Asia/Jakarta)',
        'Asia/Makassar' => 'WITA (Asia/Makassar)',
        'Asia/Jayapura' => 'WIT (Asia/Jayapura)',
    ];

    public const ABOUT_MAX = 500;

    /** @param  array<string, mixed>  $values */
    private function __construct(private Tenant $tenant, private array $values) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant, (array) $tenant->profile);
    }

    public function name(): string
    {
        return (string) $this->tenant->name;
    }

    public function timezone(): string
    {
        return $this->tenant->timezone ?: (string) config('app.timezone');
    }

    /** The stored logo, only when it is one of this agency's own uploads. */
    public function logoPath(): ?string
    {
        $path = $this->string('logo');

        return $path !== null && preg_match('#^landing/'.preg_quote((string) $this->tenant->getKey(), '#').'/[A-Za-z0-9._-]+$#', $path) ? $path : null;
    }

    public function logoUrl(): ?string
    {
        return Embeds::image($this->logoPath(), $this->tenant->getKey());
    }

    public function color(): ?string
    {
        $color = $this->string('color');

        return $color !== null && preg_match(self::COLOR_PATTERN, $color) ? strtolower($color) : null;
    }

    /** The business WhatsApp as 62812..., or null. */
    public function whatsapp(): ?string
    {
        return PhoneNumber::whatsapp($this->string('whatsapp'));
    }

    /** "0812-3456-7890" style for text, from the stored 62... number. */
    public function whatsappText(): ?string
    {
        $wa = $this->whatsapp();

        return $wa !== null ? '0'.substr($wa, 2) : null;
    }

    public function email(): ?string
    {
        $email = $this->string('email');

        return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public function address(): ?string
    {
        return $this->string('address');
    }

    public function about(): ?string
    {
        $about = $this->string('about');

        return $about !== null ? mb_substr($about, 0, self::ABOUT_MAX) : null;
    }

    public function website(): ?string
    {
        $url = $this->string('website');

        return $url !== null && preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    /**
     * The values the form saves, cleaned. Unknown keys are dropped.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function clean(array $input): array
    {
        $text = fn (string $k, int $max) => filled($input[$k] ?? null) ? mb_substr(trim((string) $input[$k]), 0, $max) : null;
        $logo = is_array($input['logo'] ?? null) ? (reset($input['logo']) ?: null) : ($input['logo'] ?? null);

        return array_filter([
            'logo' => is_string($logo) && $logo !== '' ? $logo : null,
            'color' => filled($input['color'] ?? null) && preg_match(self::COLOR_PATTERN, (string) $input['color']) ? strtolower((string) $input['color']) : null,
            'whatsapp' => PhoneNumber::whatsapp($input['whatsapp'] ?? null),
            'email' => $text('email', 190),
            'address' => $text('address', 500),
            'about' => $text('about', self::ABOUT_MAX),
            'website' => $text('website', 255),
        ], fn ($v) => $v !== null);
    }

    private function string(string $key): ?string
    {
        $v = $this->values[$key] ?? null;

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
