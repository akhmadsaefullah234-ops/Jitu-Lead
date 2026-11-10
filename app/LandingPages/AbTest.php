<?php

namespace App\LandingPages;

use App\Automation\RetailStages;
use App\Enums\StageType;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\Shipment;
use App\Models\Stage;
use App\Shipping\Carriers\ShipmentStatus;
use App\Shipping\ShippingQuote;
use Illuminate\Http\Request;

/**
 * The built-in A/B test of a landing page: page A (the one with ab_test) and page B (ab_test.partner_id, which may
 * stay a draft) are served 50:50 at A's own address with ?v=auto (?v=a / ?v=b show one of them). The split is
 * random per visitor but stable (from the visitor's IP and browser), since public pages keep no cookie. Each
 * visit served this way counts in ab_views; a lead from either page records its variant (leads.ab_variant, and
 * ab_variant next to the UTM tags). Runs inside the tenant.
 */
class AbTest
{
    public const VARIANTS = ['A', 'B'];

    /** Page B of a running test from page A, or null. */
    public static function partner(LandingPage $a): ?LandingPage
    {
        $test = (array) $a->ab_test;
        $id = (int) ($test['partner_id'] ?? 0);

        return ! empty($test['enabled']) && $id > 0 && $id !== (int) $a->getKey() ? LandingPage::query()->find($id) : null;
    }

    /** Page A of the running test that $b is page B of, or null. */
    public static function owner(LandingPage $b): ?LandingPage
    {
        return LandingPage::query()->whereKeyNot($b->getKey())->whereNotNull('ab_test')->get()
            ->first(fn (LandingPage $a) => ! empty($a->ab_test['enabled']) && (int) ($a->ab_test['partner_id'] ?? 0) === (int) $b->getKey());
    }

    /**
     * The page to show for a visit to $a with ?v=..., and its variant; null when there is no test (show $a as usual).
     *
     * @return array{0: LandingPage, 1: string}|null
     */
    public static function pick(Request $request, LandingPage $a): ?array
    {
        $v = strtolower((string) $request->query('v', ''));

        if (! in_array($v, ['auto', 'a', 'b'], true) || ($b = self::partner($a)) === null) {
            return null;
        }

        $variant = match ($v) {
            'a' => 'A',
            'b' => 'B',
            default => crc32($request->ip().'|'.$request->userAgent().'|'.$a->getKey()) % 2 === 0 ? 'A' : 'B',
        };

        return [$variant === 'A' ? $a : $b, $variant];
    }

    /** The variant a submission from $page belongs to: what the page sent, when it really is that side of a test. */
    public static function variantOf(?LandingPage $page, mixed $sent): ?string
    {
        if ($page === null || ! in_array($sent, self::VARIANTS, true)) {
            return null;
        }

        return match ($sent) {
            'A' => self::partner($page) !== null ? 'A' : null,
            default => self::owner($page) !== null ? 'B' : null,
        };
    }

    /**
     * The comparison: per variant, visitors (served through the test address), leads, form fill rate, closings
     * (reached Dikemas & dikirim, Selesai or a won stage), failed deliveries, ad spend (typed in by the owner) and
     * the cost per closing.
     *
     * @return list<array{variant: string, page: string, visitors: int, leads: int, rate: ?float, closings: int, failed: int, spend: ?int, cost_per_closing: ?int}>
     */
    public static function report(LandingPage $a): array
    {
        $b = self::partner($a) ?? LandingPage::query()->find((int) ($a->ab_test['partner_id'] ?? 0));
        $test = (array) $a->ab_test;
        $retail = app(RetailStages::class);
        $closingStages = array_values(array_filter([
            $retail->stage(RetailStages::SHIPPING)?->getKey(), $retail->stage(RetailStages::DONE)?->getKey(),
            ...Stage::query()->where('type', StageType::Won->value)->pluck('id')->all(),
        ]));
        $rows = [];

        foreach (['A' => $a, 'B' => $b] as $variant => $page) {
            if ($page === null) {
                continue;
            }

            $leads = Lead::query()->where('landing_page_id', $page->getKey())->where('ab_variant', $variant);
            $count = (clone $leads)->count();
            $closings = (clone $leads)->where(fn ($q) => $q->whereIn('stage_id', $closingStages)->orWhereNotNull('shipped_at'))->count();
            $failed = Shipment::query()->whereIn('lead_id', (clone $leads)->select('id'))
                ->whereIn('status', [ShipmentStatus::DELIVERY_FAILED, ShipmentStatus::RETURNING, ShipmentStatus::RETURNED])->distinct()->count('lead_id');
            $spend = isset($test['spend_'.strtolower($variant)]) && $test['spend_'.strtolower($variant)] !== '' ? (int) $test['spend_'.strtolower($variant)] : null;
            $visitors = (int) $page->ab_views;

            $rows[] = [
                'variant' => $variant, 'page' => (string) $page->title, 'visitors' => $visitors, 'leads' => $count,
                'rate' => $visitors > 0 ? round($count / $visitors * 100, 1) : null,
                'closings' => $closings, 'failed' => $failed, 'spend' => $spend,
                'cost_per_closing' => $spend !== null && $closings > 0 ? (int) round($spend / $closings) : null,
            ];
        }

        return $rows;
    }

    /** "Rp 45.000" or "-". */
    public static function money(?int $amount): string
    {
        return $amount === null ? '-' : ShippingQuote::rupiah($amount);
    }
}
