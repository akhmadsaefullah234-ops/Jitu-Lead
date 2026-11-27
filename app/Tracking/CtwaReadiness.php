<?php

namespace App\Tracking;

use App\Automation\PropertyStageAutomationSettings;
use App\Enums\WaChannelType;
use App\Filament\Pages\TrackingSettings;
use App\Models\ConversionEvent;
use App\Models\Lead;
use App\Models\TrackingSetting;
use App\Models\WaChannel;
use App\Support\CurrentTenant;
use App\WhatsApp\BusinessLabels;
use Illuminate\Support\Carbon;

/**
 * "Siap iklan CTWA": whether a property agency's Click-to-WhatsApp ads can learn from the CRM, check by check, and
 * what reached Meta in the last 30 days. Read only: it never sends or changes anything. Must run inside the tenant.
 */
class CtwaReadiness
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** Something the app cannot see and the owner checks by hand. */
    public const MANUAL = 'manual';

    /** @return list<array{key: string, title: string, state: string, detail: string}> */
    public function checks(): array
    {
        $tenant = app(CurrentTenant::class)->get();
        $settings = TrackingSetting::current();
        $pipeline = $settings?->pipeline() ?? TrackingSetting::starting() ?? ['enabled' => false, 'stages' => [], 'hot' => null, 'messaging' => false, 'contact' => false];
        $last = (array) (($settings?->last_results ?? [])['meta'] ?? []);
        $gateways = WaChannel::query()->where('type', WaChannelType::Gateway->value)->get();
        $since = now()->subDays(30);
        $waLeads = Lead::query()->where('created_at', '>=', $since)->whereHas('conversation');
        $fromAds = (clone $waLeads)->get()->filter(fn (Lead $l) => BusinessLabels::fromAd($l))->count();
        $checks = [];

        $pixel = filled($settings?->meta_pixel_id) && filled($settings?->credential('meta_capi_token'));
        $checks[] = ['key' => 'pixel', 'title' => 'Pixel (dataset) dan token Conversions API Meta terisi', 'state' => $pixel ? self::OK : self::FAIL,
            'detail' => $pixel ? 'Pixel '.$settings->meta_pixel_id.' dengan token tersimpan.' : 'Isi Pixel ID dan token Conversions API di Pelacakan iklan > Atur pelacakan.'];

        $events = array_values(array_unique($pipeline['stages']));
        $checks[] = ['key' => 'pipeline', 'title' => 'Event dari pipeline menyala', 'state' => $pipeline['enabled'] && $events !== [] ? self::OK : self::FAIL,
            'detail' => $pipeline['enabled'] ? 'Event: '.(implode(', ', $events) ?: 'belum ada tahap yang dipetakan').'.' : 'Mati. Nyalakan di Pelacakan iklan > Event dari pipeline, atau pakai "Samakan dengan bawaan Jitu".'];

        $automation = PropertyStageAutomationSettings::of($tenant);
        $checks[] = ['key' => 'automation', 'title' => 'Otomatisasi tahap menyala', 'state' => $automation->enabled('contacted') && $automation->enabled('qualified') ? self::OK : self::WARN,
            'detail' => $automation->chosen() ? 'Lead pindah ke Terkualifikasi dan seterusnya sendiri, sehingga QualifiedLead dan Schedule terkirim tanpa menunggu agen.'
                : 'Belum menyala: QualifiedLead dan Schedule hanya terkirim bila agen memindah lead. Nyalakan di Pengaturan & Alat > Otomatisasi tahap.'];

        $checks[] = ['key' => 'sent', 'title' => 'Conversions API terkirim', 'state' => match (true) {
            $last === [] => self::WARN, (bool) ($last['ok'] ?? false) => self::OK, default => self::FAIL,
        }, 'detail' => $last === [] ? 'Belum ada event yang terkirim dari server. Pakai "Uji kirim event" di atas.' : 'Kiriman terakhir: '.TrackingSettings::lastResult($last).'.'];

        $total = (clone $waLeads)->count();
        $checks[] = ['key' => 'ads', 'title' => 'Chat dari iklan tertangkap', 'state' => $fromAds > 0 ? self::OK : self::WARN,
            'detail' => "{$fromAds} dari {$total} lead WhatsApp 30 hari terakhir membawa data iklan (ID iklan atau ctwa_clid).".($fromAds === 0 ? ' Belum ada; pastikan iklan Klik ke WhatsApp mengarah ke nomor yang tersambung di sini.' : '')];

        foreach ($gateways as $channel) {
            $label = BusinessLabels::settings($channel);
            $found = BusinessLabels::checklist($channel);
            $missing = array_keys(array_filter($found, fn ($f) => $f === null));
            $state = match (true) {
                ! $label['enabled'] => self::WARN,
                $label['known'] === [] || BusinessLabels::staleList($label['known']) || $missing !== [] => self::FAIL,
                default => self::OK,
            };
            $checks[] = ['key' => 'labels-'.$channel->getKey(), 'title' => 'Label WhatsApp Business di '.$channel->name, 'state' => $state, 'detail' => match (true) {
                ! $label['enabled'] => 'Label otomatis mati. Buka Koneksi WhatsApp > nomor ini > "Pakai bawaan Jitu".',
                $label['known'] === [] => 'Label belum pernah dimuat. '.BusinessLabels::GUIDE,
                BusinessLabels::staleList($label['known']) => 'Gateway hanya melihat '.implode(', ', $label['known']).' (daftar bawaan WhatsApp), belum label bisnis. '.BusinessLabels::GUIDE,
                $missing !== [] => 'Belum ada di akun: '.implode(', ', $missing).'. '.BusinessLabels::GUIDE,
                default => 'Prospek, Order baru, dan Lunas ada di akun.',
            }];
        }

        $checks[] = ['key' => 'sharing', 'title' => 'Berbagi data WhatsApp Business ke Meta', 'state' => self::MANUAL,
            'detail' => 'Tidak bisa dibaca dari sini. Di HP: WhatsApp Bisnis > Alat bisnis > Iklan / Akun yang terhubung, pastikan akun Meta terhubung dan berbagi data diizinkan. Tanpa ini label tidak menjadi sinyal iklan.'];

        if ($pipeline['messaging'] && ! TrackingSettings::hasCloudApiNumber()) {
            $checks[] = ['key' => 'messaging', 'title' => 'Conversions API for Business Messaging menyala tanpa nomor Cloud API', 'state' => self::FAIL, 'detail' => TrackingSettings::MESSAGING_WARNING];
        }

        return $checks;
    }

    /**
     * What went to Meta in the last 30 days: events per name (and by stage move, Hot, reply or form), labels per
     * status, for matching with Ads Manager.
     *
     * @return array{events: array<string, array<string, int>>, labels: array<string, array<string, int>>, since: Carbon}
     */
    public function summary(int $days = 30): array
    {
        $since = now()->subDays($days);
        $events = [];

        foreach (ConversionEvent::query()->where('created_at', '>=', $since)->selectRaw('event_name, trigger, count(*) as n')->groupBy('event_name', 'trigger')->get() as $row) {
            $events[$row->event_name][$row->trigger] = (int) $row->n;
        }

        $labels = [];

        foreach (WaChannel::query()->where('type', WaChannelType::Gateway->value)->get() as $channel) {
            foreach (BusinessLabels::summary($channel, $days) as $label => $counts) {
                foreach ($counts as $status => $n) {
                    $labels[$label][$status] = ($labels[$label][$status] ?? 0) + $n;
                }
            }
        }

        return ['events' => $events, 'labels' => $labels, 'since' => Carbon::parse($since)];
    }
}
