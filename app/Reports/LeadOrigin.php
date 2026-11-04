<?php

namespace App\Reports;

use Illuminate\Database\Eloquent\Builder;

/**
 * Where a lead came from, in four groups the Dashboard and the Lead list share: WhatsApp from an ad (Click to
 * WhatsApp: an ad id, or the chat opened from an ad), WhatsApp that started on its own, a form or landing page,
 * and everything else (import, typed in by hand, other sources).
 */
final class LeadOrigin
{
    public const WA_ADS = 'wa_ads';

    public const WA_ORGANIC = 'wa_organic';

    public const FORM = 'form';

    public const MANUAL = 'manual';

    public const LABELS = [
        self::WA_ADS => 'WhatsApp dari iklan',
        self::WA_ORGANIC => 'WhatsApp organik',
        self::FORM => 'Formulir / landing',
        self::MANUAL => 'Impor / manual / lainnya',
    ];

    /** SQL giving the group of the lead in "leads"; needs nothing joined. */
    public static function sql(): string
    {
        $type = '(select ls.type from lead_sources ls where ls.id = leads.lead_source_id)';
        $adChat = 'exists (select 1 from wa_conversations wc where wc.lead_id = leads.id and wc.ad_entry_at is not null)';

        return "case
            when {$type} = 'form' then '".self::FORM."'
            when leads.ad_id is not null or {$type} = 'ads' or {$adChat} then '".self::WA_ADS."'
            when {$type} = 'whatsapp' then '".self::WA_ORGANIC."'
            else '".self::MANUAL."' end";
    }

    /** Leads that came from an ad: Click to WhatsApp, or a form whose visit carried an ad campaign (UTM). */
    public static function fromAds(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereRaw('('.self::sql().') = ?', [self::WA_ADS])
            ->orWhereNotNull('leads.custom_fields->kampanye->utm_source'));
    }

    public static function apply(Builder $query, string $origin): Builder
    {
        return array_key_exists($origin, self::LABELS) ? $query->whereRaw('('.self::sql().') = ?', [$origin]) : $query;
    }
}
