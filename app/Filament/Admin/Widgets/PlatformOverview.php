<?php

namespace App\Filament\Admin\Widgets;

use App\Billing\PlatformStats;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $s = PlatformStats::summary();
        $rp = fn (int $n) => 'Rp '.number_format($n, 0, ',', '.');

        return [
            Stat::make('Total agensi', $s['tenants'])->description("{$s['new_7d']} baru dalam 7 hari".($s['suspended'] ? ", {$s['suspended']} ditangguhkan" : ''))->color('primary'),
            Stat::make('Sedang uji coba', $s['trial'])->description("{$s['trial_ended']} uji coba sudah habis")->color('warning'),
            Stat::make('Berlangganan aktif', $s['active'])->description("{$s['past_due']} jatuh tempo, {$s['read_only']} hanya-baca")->color('success'),
            Stat::make('Perkiraan pendapatan/bulan', $rp($s['mrr']))->description('Paket aktif saja, tanpa add-on')->color('success'),
            Stat::make('Permintaan paket menunggu', $s['pending_requests'])->description('Cek transfer, lalu setujui')->color($s['pending_requests'] ? 'danger' : 'gray'),
            Stat::make('Chat belum dibalas', $s['open_chats'])->description('Dari pengguna aplikasi')->color($s['open_chats'] ? 'danger' : 'gray'),
        ];
    }
}
