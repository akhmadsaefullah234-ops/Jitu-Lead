<?php

namespace App\Tracking;

use App\Automation\PropertyStages;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\TrackingSetting;
use App\Models\WaChannel;
use App\WhatsApp\BusinessLabels;

/**
 * Jitu's ad-tracking defaults for a property agency, the same flow a shop uses: what Meta hears from the pipeline
 * (Pelacakan iklan > Event dari pipeline) and which WhatsApp Business labels go on (Koneksi WhatsApp). A new property
 * agency starts with them; an agency that set its own keeps it until an admin presses "Samakan dengan bawaan Jitu",
 * which lists the differences first. Must run inside the tenant.
 */
class PropertyAdDefaults
{
    /** Event per pipeline step; Lead baru reports Lead when the lead is made (a form lead already sent its own). */
    public const STAGES = [
        PropertyStages::NEW => 'Lead',
        PropertyStages::QUALIFIED => 'QualifiedLead',
        PropertyStages::SURVEY => 'Schedule',
        PropertyStages::BOOKING => 'InitiateCheckout',
        PropertyStages::CLOSING => 'Purchase',
    ];

    public const HOT = 'QualifiedLead';

    /** tenants.pipeline_settings.ad_defaults of an agency that starts with these defaults. */
    public const FLAG = 'jitu';

    /** The form and landing page event. */
    public const FORM_EVENT = 'Lead';

    /** @return array{hot: string, stages: array<int, string>, messaging: bool, contact: bool} */
    public static function pipeline(): array
    {
        $steps = new PropertyStages;
        $stages = [];

        foreach (self::STAGES as $key => $event) {
            if ($stage = $steps->stage($key)) {
                $stages[$stage->getKey()] = $event;
            }
        }

        // Conversions API for Business Messaging only works for Cloud API numbers: off, as most agencies use a QR gateway.
        return ['hot' => self::HOT, 'stages' => $stages, 'messaging' => false, 'contact' => true];
    }

    /**
     * What "Samakan dengan bawaan Jitu" would change: [what, now, after] per difference, in words.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function differences(?TrackingSetting $setting): array
    {
        $now = $setting !== null ? $setting->pipeline() : TrackingSetting::starting() ?? ['enabled' => false, 'hot' => null, 'stages' => [], 'messaging' => false, 'contact' => false];
        $jitu = ['enabled' => true] + self::pipeline();
        $off = 'tidak ada';
        $yes = fn (bool $on) => $on ? 'hidup' : 'mati';
        $out = [];

        if ($now['enabled'] !== $jitu['enabled']) {
            $out[] = ['Kirim event dari pipeline', $yes($now['enabled']), $yes(true)];
        }

        if ($setting !== null && $setting->eventFor('meta') !== self::FORM_EVENT) {
            $out[] = ['Event formulir dan landing (Meta)', $setting->eventFor('meta'), self::FORM_EVENT];
        }

        foreach (Stage::query()->orderBy('position')->orderBy('id')->get() as $stage) {
            $before = $now['stages'][$stage->getKey()] ?? null;
            $after = $jitu['stages'][$stage->getKey()] ?? null;

            if ($before !== $after) {
                $out[] = ['Tahap '.$stage->name, $before ?? $off, $after ?? $off];
            }
        }

        if ($now['hot'] !== $jitu['hot']) {
            $out[] = ['Lead ditandai Hot', $now['hot'] ?? $off, $jitu['hot']];
        }

        if ($now['contact'] !== $jitu['contact']) {
            $out[] = ['Klien membalas pertama kali (Contact)', $yes($now['contact']), $yes($jitu['contact'])];
        }

        if ($now['messaging'] !== $jitu['messaging']) {
            $out[] = ['Conversions API for Business Messaging', $yes($now['messaging']), $yes($jitu['messaging'])];
        }

        return $out;
    }

    /** Writes the defaults, keeping the pixel ids, tokens and the other platforms' choices. */
    public static function apply(?TrackingSetting $setting = null): TrackingSetting
    {
        $setting ??= TrackingSetting::current() ?? new TrackingSetting;
        $setting->fill(['meta_event' => self::FORM_EVENT, 'pipeline_events' => ['enabled' => true] + self::pipeline()])->save();

        return $setting;
    }

    /**
     * A new property agency's tracking: the defaults on (TrackingSetting::starting), waiting for a pixel and token,
     * so nothing goes out before. Nothing is stored in tracking_settings: the agency's first save there keeps them.
     */
    public static function provision(Tenant $tenant): void
    {
        if ($tenant->industry()->key === 'properti' && data_get($tenant->pipeline_settings, 'ad_defaults') === null && TrackingSetting::current()?->pipeline_events === null) {
            $tenant->forceFill(['pipeline_settings' => array_merge((array) $tenant->pipeline_settings, ['ad_defaults' => self::FLAG])])->save();
        }
    }

    /**
     * The label settings "Pakai bawaan Jitu" stores on a QR gateway number: on, only chats from ads, and the three
     * standard labels (Prospek, Order baru, Lunas) on the property steps.
     */
    public static function labels(WaChannel $channel): array
    {
        return ['enabled' => true, 'ads_only' => true, 'rules' => BusinessLabels::defaults($channel->tenant()->first()?->industry()->key)['rules']]
            + ['known' => BusinessLabels::settings($channel)['known']];
    }
}
