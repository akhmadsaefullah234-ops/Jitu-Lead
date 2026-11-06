<?php

namespace App\Reports;

use App\Enums\WaChannelStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Shipping\Carriers\Carriers;
use App\Shipping\Shipments\ShippingSummary;
use App\Support\CurrentTenant;
use App\Support\Period;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

/**
 * The text of a report (WhatsApp, and the same in the email): the numbers of App\Reports\DashboardData for the
 * period of the report, seen as the person it is for (an agent: only their own leads), so it always matches the
 * Dashboard. Daily: "Kemarin"; weekly: the 7 days before Monday; monthly: last month. Only lines that apply to
 * the agency's industry and are not zero are shown. Never a buyer's name or number.
 */
class DailyReport
{
    public function __construct(private CurrentTenant $current) {}

    public static function period(string $kind, Tenant $tenant): Period
    {
        $tz = $tenant->timezone ?: 'Asia/Jakarta';

        return match ($kind) {
            'weekly' => Period::make(Period::CUSTOM, CarbonImmutable::now($tz)->subDays(7)->toDateString(), CarbonImmutable::now($tz)->subDay()->toDateString(), $tz),
            'monthly' => Period::make('last_month', timezone: $tz),
            default => Period::make('yesterday', timezone: $tz),
        };
    }

    /** @return array{text: string, period: Period, url: string} */
    public function build(Tenant $tenant, User $user, string $kind = 'daily'): array
    {
        $period = self::period($kind, $tenant);

        return $this->as($tenant, $user, function () use ($tenant, $kind, $period) {
            $url = Dashboard::getUrl($period->toQuery(), panel: 'app', tenant: $tenant);

            return ['text' => $this->text($tenant, $kind, $period, $url), 'period' => $period, 'url' => $url];
        });
    }

    /** Runs $fn inside the agency, signed in as $user, the way the Dashboard would for them. */
    public function as(Tenant $tenant, User $user, callable $fn): mixed
    {
        $guard = Auth::guard();
        $previous = $guard->hasUser() ? $guard->user() : null;
        $guard->setUser($user);

        try {
            return $this->current->run($tenant, $fn);
        } finally {
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }
    }

    private function text(Tenant $tenant, string $kind, Period $period, string $url): string
    {
        $data = app()->make(DashboardData::class);
        $seesTeam = $this->current->roleOf(Auth::user())?->seesAllLeads() ?? false;
        $s = $data->summary($period);
        $title = ['weekly' => 'Ringkasan mingguan', 'monthly' => 'Ringkasan bulanan'][$kind] ?? 'Laporan harian';
        $before = ['weekly' => '7 hari sebelumnya', 'monthly' => 'bulan sebelumnya'][$kind] ?? 'hari sebelumnya';

        $out = ["📊 *{$title} {$tenant->name}*", $this->when($kind, $period).($seesTeam ? '' : ' · lead milik Anda'), ''];

        // Leads
        $new = (int) $s['new']['now'];
        $out[] = "👥 *Lead baru: {$this->n($new)}*".$this->compare($new, $s['new']['before'], $before);

        if ($new > 0) {
            $origin = $data->sources($period);
            $names = [LeadOrigin::WA_ADS => 'Iklan CTWA', LeadOrigin::FORM => 'Formulir / landing', LeadOrigin::WA_ORGANIC => 'WhatsApp organik', LeadOrigin::MANUAL => 'Impor / manual'];
            foreach ($names as $key => $label) {
                $this->line($out, $label, $origin[$key] ?? 0);
            }
            $this->line($out, 'Hot', $s['hot']['now'] ?? 0);

            $products = $this->products($data->products($period));
            if ($products !== []) {
                $out[] = '_Per produk:_';
                foreach ($products as $name => $count) {
                    $this->line($out, $name, $count);
                }
            }
        }

        // Sales
        $block = [];
        if ($data->isRetail()) {
            $this->line($block, 'Order masuk', $s['orders']['now']);
            $this->line($block, 'Dibayar / dikirim', $s['paid']['now']);
            $this->line($block, 'Selesai', $s['done']['now']);
            $this->line($block, 'Batal', $s['cancelled']['now']);
            if ((int) $s['sales']['now'] > 0) {
                $block[] = '• Nilai penjualan: *'.$this->money((int) $s['sales']['now']).'*'.$this->compare((int) $s['sales']['now'], $s['sales']['before'], $before, money: true);
            }
            if ($ad = $this->bestAd($data->ads($period))) {
                $block[] = "• Iklan terbaik: {$ad}";
            }
            $this->block($out, '🛒 *Penjualan*', $block);

            if (Carriers::configured($tenant)) {
                $n = app(ShippingSummary::class)->numbers($period->start, $period->end, LeadResource::onlyVisibleLeads(Lead::query()));
                $block = [];
                $this->line($block, 'Resi otomatis', $n['auto']);
                if ($n['auto_cod'] > 0) {
                    $block[] = '• Total COD resi otomatis: '.$this->money($n['auto_cod']);
                }
                $this->line($block, 'Resi gagal dibuat', $n['failed']);
                $this->line($block, 'Perlu dilengkapi untuk resi', $n['skipped']);
                $this->line($block, 'Resi belum dicetak (saat ini)', $n['unprinted']);
                $this->line($block, 'Kiriman perlu tindakan (saat ini)', $n['action']);
                $this->block($out, '📦 *Pengiriman*', $block);
            }
        } else {
            $this->line($block, 'Survei terjadwal', $s['surveys_planned']['now']);
            $this->line($block, 'Survei terlaksana', $s['surveys_done']['now']);
            $this->line($block, 'Booking', $s['bookings']['now']);
            $this->line($block, 'Akad / closing', $s['closings']['now']);
            if ((int) $s['closing_value']['now'] > 0) {
                $block[] = '• Nilai transaksi: *'.$this->money((int) $s['closing_value']['now']).'*';
            }
            $this->block($out, '🏠 *Properti*', $block);
        }

        // AI and automatic messages
        $a = $data->automation($period);
        $block = [];
        $this->line($block, 'Balasan AI', $a['ai_replies']);
        $this->line($block, 'Diserahkan ke tim (handoff)', $a['handoffs']);
        $this->line($block, 'Follow-up terkirim', $a['follow_ups_sent']);
        $this->line($block, '"Tanya produk" terkirim', $a['ask_sent']);
        $this->line($block, 'Sambutan formulir terkirim', $a['welcome_sent']);
        $this->line($block, 'Sambutan gagal', $a['welcome_failed']);
        if ($seesTeam) {
            $this->line($block, 'Event konversi iklan gagal', $a['meta_failed']);
        }
        $this->block($out, '🤖 *AI & otomatis*', $block);

        // Right now
        $status = $data->status();
        $block = [];
        $this->line($block, 'Voice note belum dibalas', $a['voice_unanswered']);
        $this->line($block, 'Lead belum ditangani', $status['unattended'] ?? 0);
        $this->line($block, 'Tugas terlambat', $status['overdue']);
        $this->line($block, 'Draf AI menunggu persetujuan', $a['drafts_waiting']);
        $down = $status['channels']->reject(fn (WaChannel $c) => $c->status === WaChannelStatus::Connected)->pluck('name');
        if ($down->isNotEmpty()) {
            $block[] = '• Nomor WhatsApp terputus: '.$down->implode(', ');
        }
        $this->block($out, '⚠️ *Perlu perhatian (saat ini)*', $block);

        $out[] = '';
        $out[] = '🔗 Dasbor: '.$url;

        return implode("\n", $out);
    }

    private function when(string $kind, Period $period): string
    {
        $a = $period->start->setTimezone($period->timezone)->locale('id');
        $b = $period->end->setTimezone($period->timezone)->locale('id');

        return match ($kind) {
            'weekly' => $a->translatedFormat('j M').' – '.$b->translatedFormat('j M Y'),
            'monthly' => $a->translatedFormat('F Y'),
            default => 'Kemarin, '.$a->translatedFormat('l j F Y'),
        };
    }

    /**
     * The 5 products with the most leads, the rest (and leads without a product) as "Lainnya".
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function products(array $rows): array
    {
        $named = collect($rows)->filter(fn ($r) => $r['id'] !== null && $r['leads'] > 0)->sortByDesc('leads')->values();

        if ($named->isEmpty()) {
            return [];
        }

        $top = $named->take(5)->mapWithKeys(fn ($r) => [$r['name'] => (int) $r['leads']])->all();
        $rest = (int) collect($rows)->sum('leads') - array_sum($top);

        return $top + ($rest > 0 ? ['Lainnya' => $rest] : []);
    }

    /** The ad with the most orders, then the most leads. @param  list<array<string, mixed>>  $ads */
    private function bestAd(array $ads): ?string
    {
        $best = collect($ads)->filter(fn ($r) => $r['ad'] !== null && ($r['orders'] + $r['leads']) > 0)
            ->sortByDesc(fn ($r) => [$r['orders'], $r['leads']])->first();

        if ($best === null) {
            return null;
        }

        return '"'.mb_strimwidth((string) $best['name'], 0, 60, '…').'" ('.implode(', ', array_filter([
            $best['orders'] > 0 ? $this->n($best['orders']).' order' : null,
            $best['leads'] > 0 ? $this->n($best['leads']).' lead' : null,
        ])).')';
    }

    /** @param  list<string>  $out */
    private function line(array &$out, string $label, mixed $value): void
    {
        if ((int) $value !== 0) {
            $out[] = "• {$label}: {$this->n((int) $value)}";
        }
    }

    /** @param  list<string>  $out  @param  list<string>  $lines */
    private function block(array &$out, string $heading, array $lines): void
    {
        if ($lines !== []) {
            array_push($out, '', $heading, ...$lines);
        }
    }

    private function compare(int $now, mixed $before, string $label, bool $money = false): string
    {
        if ($before === null) {
            return '';
        }

        $before = (int) $before;
        $diff = $now - $before;
        $show = fn (int $v) => $money ? $this->money($v) : $this->n($v);

        return " ({$label} {$show($before)}".($diff === 0 ? ', sama' : ($diff > 0 ? ', naik '.$show($diff) : ', turun '.$show(-$diff))).')';
    }

    private function n(int $v): string
    {
        return number_format($v, 0, ',', '.');
    }

    private function money(int $v): string
    {
        return 'Rp '.number_format($v, 0, ',', '.');
    }
}
