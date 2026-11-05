<?php

namespace App\Welcome;

use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\Tenant;

/**
 * What the automatic WhatsApp welcome of a form looks like (lead_forms.welcome), as a landing page that uses the
 * form may change it (landing_pages.welcome: follow the form, its own text, or off for that page).
 *
 * The text comes from the page, else the form, else the product the lead asks about (properties.welcome_text),
 * else the built-in text of the agency's industry.
 */
final class WelcomeSettings
{
    public const DELAYS = [1 => '1 menit', 2 => '2 menit', 5 => '5 menit', 10 => '10 menit'];

    public const DEFAULT_DELAY = 2;

    public const PAGE_MODES = ['form' => 'Ikuti pengaturan formulir', 'custom' => 'Pakai teks khusus halaman ini', 'off' => 'Matikan di halaman ini'];

    public const VARIABLES = '{nama}, {produk}, {agensi}, {agen}, {ringkasan}, {total}, {whatsapp}, {alamat}, {varian}, {warna}, {jumlah}, {ongkir}, {kurir}, {jawaban:kunci}';

    public const RETAIL_TEXT = 'Halo kak {nama} 😊 Terima kasih sudah mengisi pesanan {produk} di {agensi}. Ini ringkasannya ya kak: {ringkasan}. Mohon dicek, kalau sudah sesuai balas *OK* ya kak, pesanan langsung kami proses 🙏';

    public const PROPERTY_TEXT = 'Halo {nama} 😊 Terima kasih sudah mengisi formulir {produk} di {agensi}. Saya {agen} yang akan membantu. Mau saya jadwalkan survei lokasi, atau saya kirimkan brosur dan daftar harganya dulu?';

    private function __construct(
        public readonly bool $active,
        public readonly int $delayMinutes,
        public readonly ?string $text,
        public readonly ?int $mediaAssetId,
    ) {}

    public static function for(LeadForm $form, ?LandingPage $page = null): self
    {
        $own = (array) $form->welcome;
        $pageSettings = (array) ($page?->welcome ?? []);
        $mode = $pageSettings['mode'] ?? 'form';

        if ($mode === 'off') {
            return new self(false, self::delay($own['delay'] ?? null), null, null);
        }

        if ($mode === 'custom') {
            return new self(
                true,
                self::delay($pageSettings['delay'] ?? $own['delay'] ?? null),
                self::text($pageSettings['text'] ?? null) ?? self::text($own['text'] ?? null),
                self::id($pageSettings['media_asset_id'] ?? null) ?? self::id($own['media_asset_id'] ?? null),
            );
        }

        return new self(! empty($own['active']), self::delay($own['delay'] ?? null), self::text($own['text'] ?? null), self::id($own['media_asset_id'] ?? null));
    }

    /** What a new form starts with: on for a shop, off otherwise. The text stays empty so the product's text applies. */
    public static function newForm(?Tenant $tenant): array
    {
        return ['active' => $tenant?->industry()->key === 'retail', 'delay' => self::DEFAULT_DELAY, 'text' => null, 'media_asset_id' => null];
    }

    /** The text this lead gets: the form's (or page's), else its product's, else the industry's built-in one. */
    public function textFor(Lead $lead): string
    {
        return $this->text
            ?? self::text($lead->property?->welcome_text)
            ?? self::builtIn($lead->tenant);
    }

    public static function builtIn(?Tenant $tenant): string
    {
        return $tenant?->industry()->key === 'properti' ? self::PROPERTY_TEXT : self::RETAIL_TEXT;
    }

    private static function delay(mixed $value): int
    {
        return array_key_exists((int) $value, self::DELAYS) ? (int) $value : self::DEFAULT_DELAY;
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function id(mixed $value): ?int
    {
        return filled($value) ? (int) $value : null;
    }
}
