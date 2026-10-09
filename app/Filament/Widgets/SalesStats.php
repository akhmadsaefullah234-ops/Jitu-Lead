<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\VisibleLeads;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SalesStats extends StatsOverviewWidget
{
    use VisibleLeads;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    protected function getColumns(): int|array
    {
        return ['default' => 2, 'lg' => 4];
    }

    protected function getStats(): array
    {
        $since = now()->subDays(30);
        $new = $this->leads()->where('created_at', '>=', $since)->count();
        $previous = $this->leads()->whereBetween('created_at', [now()->subDays(60), $since])->count();
        $won = $this->leads()->whereHas('stage', fn ($q) => $q->where('type', 'won'))->where('updated_at', '>=', $since);
        $wonCount = (clone $won)->count();
        $wonValue = (int) (clone $won)->sum('deal_value');
        $open = $this->leads()->open()->count();
        $surveys = $this->leads()->open()->whereBetween('survey_at', [now(), now()->addDays(7)])->count();

        return [
            Stat::make('Lead baru (30 hari)', number_format($new, 0, ',', '.'))
                ->description($previous > 0 ? (($new >= $previous ? '+' : '').round(($new - $previous) / $previous * 100).'% dari 30 hari sebelumnya') : 'Belum ada pembanding')
                ->descriptionIcon($new >= $previous ? Heroicon::ArrowTrendingUp : Heroicon::ArrowTrendingDown)
                ->color($new >= $previous ? 'success' : 'danger'),
            Stat::make('Lead aktif', number_format($open, 0, ',', '.'))->description('Masih di pipeline')->color('primary'),
            Stat::make('Survei 7 hari ke depan', number_format($surveys, 0, ',', '.'))->description('Jadwal yang akan datang')->color('warning'),
            Stat::make('Closing (30 hari)', number_format($wonCount, 0, ',', '.'))
                ->description('Nilai Rp '.number_format($wonValue, 0, ',', '.'))->color('success'),
        ];
    }
}
