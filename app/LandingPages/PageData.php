<?php

namespace App\LandingPages;

use App\Models\LandingPage;
use App\Models\Tenant;
use App\Models\TrackingSetting;
use App\Models\WaChannel;
use App\Support\PhoneNumber;
use Illuminate\Support\Str;

/**
 * Everything the public landing view needs, worked out in one place so the
 * live page, the signed preview, and the editor's side preview draw the same.
 */
class PageData
{
    /**
     * @return array<string, mixed>
     */
    public static function for(LandingPage $page, Tenant $tenant, bool $preview): array
    {
        $sections = BlockFormat::visible($page->blocks);
        $types = array_column($sections, 'type');
        $color = preg_match(LandingPage::COLOR_PATTERN, (string) $page->color) ? $page->color : '#dc2626';
        $font = LandingPage::FONTS[$page->font] ?? LandingPage::FONTS['modern'];
        $tenantId = $tenant->getKey();

        $number = self::digits($page->whatsapp_number) ?: self::agencyNumber();
        $firstCta = collect($sections)->firstWhere('type', 'cta');
        $message = (string) ($firstCta['props']['message'] ?? 'Halo, saya tertarik dengan '.$page->title);
        $hero = collect($sections)->firstWhere('type', 'hero');

        $description = $page->description
            ?: ($hero['props']['subheadline'] ?? null)
            ?: $page->title;

        $og = Embeds::image($page->og_image, $tenantId) ?? Embeds::image($hero['props']['image'] ?? null, $tenantId) ?? Embeds::image($page->logo, $tenantId);

        return [
            'tenant' => $tenant,
            'page' => $page,
            'sections' => $sections,
            'color' => $color,
            'bodyFont' => $font[1],
            'headingFont' => $font[2],
            'logo' => Embeds::image($page->logo, $tenantId),
            'og' => $og,
            'metaTitle' => $page->meta_title ?: $page->title.' - '.$tenant->name,
            'metaDescription' => Str::limit(trim(strip_tags((string) $description)), 200),
            'waNumber' => $number,
            'waLink' => $number ? self::waLink($number, $message) : null,
            'hasForm' => in_array('form', $types, true),
            'usesScript' => (bool) array_intersect($types, ['kpr', 'countdown', 'video']),
            'tenantId' => $tenantId,
            'preview' => $preview,
            'tracking' => $preview ? null : TrackingSetting::active(),
            'settings' => $tenant->formSettings(),
            'endpoint' => $tenant->captureUrl(),
        ];
    }

    public static function waLink(string $digits, string $message): string
    {
        return 'https://wa.me/'.$digits.'?text='.rawurlencode($message);
    }

    public static function digits(?string $number): string
    {
        return ltrim((string) PhoneNumber::normalize($number), '+');
    }

    /** The agency's own WhatsApp number: the first channel that has one. */
    private static function agencyNumber(): string
    {
        return self::digits(WaChannel::query()->whereNotNull('phone')->where('phone', '!=', '')->orderBy('position')->orderBy('id')->value('phone'));
    }
}
