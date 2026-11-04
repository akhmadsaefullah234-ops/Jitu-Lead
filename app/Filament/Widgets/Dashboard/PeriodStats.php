<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Widgets\Concerns\UsesPagePeriod;
use App\Reports\DashboardData;
use App\Support\Industry;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** The headline numbers of the period, each against the period before (same length). */
class PeriodStats extends StatsOverviewWidget
{
    use UsesPagePeriod;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    protected static bool $isLazy = false;

    protected function getColumns(): int|array
    {
        return ['default' => 2, 'md' => 3, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $data = $this->data();
        $s = $data->summary($this->period());
        $retail = $data->isRetail();

        $stats = [
            $this->stat('Lead baru', $s['new']),
            $this->stat('Lead dari iklan', $s['ads'], hint: DashboardData::rate((int) $s['ads']['now'], (int) $s['new']['now']) !== null ? DashboardData::rate((int) $s['ads']['now'], (int) $s['new']['now']).'% dari lead baru' : null),
            $this->stat('Lead Hot', $s['hot']),
            $this->stat('Waktu balas pertama', $s['response'], fn ($v) => self::minutes($v), lowerIsBetter: true),
        ];

        if ($retail) {
            $orderRate = DashboardData::rate((int) $s['orders']['now'], (int) $s['new']['now']);
            $paidRate = DashboardData::rate((int) $s['paid']['now'], (int) $s['orders']['now']);

            return [...$stats,
                $this->stat('Order masuk', $s['orders']),
                $this->stat('Dibayar / dikirim', $s['paid']),
                $this->stat('Selesai', $s['done']),
                $this->stat('Batal', $s['cancelled'], lowerIsBetter: true),
                $this->stat('Nilai penjualan', $s['sales'], fn ($v) => self::rupiah($v), hint: $s['average']['now'] ? 'Rata-rata order '.self::rupiah($s['average']['now']) : null),
                Stat::make('Konversi', ($orderRate ?? 0).'% → '.($paidRate ?? 0).'%')
                    ->description('Lead → order → dibayar')->color('primary'),
            ];
        }

        return [...$stats,
            $this->stat(Industry::t('visit').' terjadwal', $s['surveys_planned']),
            $this->stat(Industry::t('visit').' terlaksana', $s['surveys_done']),
            $this->stat('Booking', $s['bookings']),
            $this->stat('Akad / closing', $s['closings'], hint: 'Nilai '.self::rupiah($s['closing_value']['now'])),
        ];
    }

    /** @param  array{now: int|float|null, before: int|float|null}  $pair */
    private function stat(string $label, array $pair, ?\Closure $format = null, bool $lowerIsBetter = false, ?string $hint = null): Stat
    {
        $format ??= fn ($v) => number_format((int) $v, 0, ',', '.');
        [$now, $before] = [$pair['now'], $pair['before']];
        $stat = Stat::make($label, $now === null ? '-' : $format($now));

        if ($now === null || $before === null) {
            return $stat->description($hint ?? 'Belum ada pembanding')->color('gray');
        }

        if ((float) $before == 0.0) {
            return $stat->description($hint ?? ($now > 0 ? 'Sebelumnya 0' : 'Sama dengan sebelumnya'))->color('gray');
        }

        $change = (int) round(($now - $before) / $before * 100);
        $good = $lowerIsBetter ? $change <= 0 : $change >= 0;

        return $stat->description(($change > 0 ? '+' : '').$change.'% dari sebelumnya'.($hint ? ' · '.$hint : ''))
            ->descriptionIcon($change >= 0 ? Heroicon::ArrowTrendingUp : Heroicon::ArrowTrendingDown)
            ->color($change === 0 ? 'gray' : ($good ? 'success' : 'danger'));
    }

    public static function rupiah(int|float|null $value): string
    {
        return 'Rp '.number_format((int) $value, 0, ',', '.');
    }

    public static function minutes(int|float|null $value): string
    {
        if ($value === null) {
            return '-';
        }

        return $value >= 60 ? floor($value / 60).' j '.round(fmod($value, 60)).' m' : round($value, 1).' menit';
    }
}
