<?php

namespace App\Filament\Pages;

use App\Actions\DemoData;
use App\Enums\Role;
use App\Filament\Widgets\GettingStarted;
use App\Filament\Widgets\LeadsBySource;
use App\Filament\Widgets\LeadsByStage;
use App\Filament\Widgets\OverdueTasks;
use App\Filament\Widgets\RecentLeads;
use App\Filament\Widgets\SalesStats;
use App\Support\CurrentTenant;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = 'Dasbor';

    protected static ?string $title = 'Dasbor';

    protected static ?int $navigationSort = 0;

    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }

    public function getWidgets(): array
    {
        return [GettingStarted::class, SalesStats::class, LeadsByStage::class, LeadsBySource::class, OverdueTasks::class, RecentLeads::class];
    }

    private function isAdmin(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user()) === Role::Admin;
    }

    protected function getHeaderActions(): array
    {
        $demo = app(DemoData::class);

        return [
            Action::make('loadDemo')->label('Isi data contoh')->icon(Heroicon::OutlinedSparkles)
                ->visible(fn () => $this->isAdmin() && ! $demo->hasLeads())
                ->requiresConfirmation()->modalHeading('Isi data contoh?')
                ->modalDescription('Membuat sekitar 30 lead dan 4 properti contoh agar dasbor dan pipeline terlihat berjalan. Bisa dihapus kapan saja tanpa menyentuh data asli Anda.')
                ->action(function () use ($demo) {
                    $count = $demo->load();
                    Notification::make()->title("{$count} lead contoh dibuat")->success()->send();
                    $this->redirect(static::getUrl());
                }),
            Action::make('clearDemo')->label('Hapus data contoh')->icon(Heroicon::OutlinedTrash)->color('gray')
                ->visible(fn () => $this->isAdmin() && $demo->hasDemo())
                ->requiresConfirmation()->modalHeading('Hapus semua data contoh?')
                ->modalDescription('Hanya lead dan properti bertanda contoh yang dihapus. Data yang Anda masukkan sendiri tetap aman.')
                ->action(function () use ($demo) {
                    $count = $demo->clear();
                    Notification::make()->title("{$count} lead contoh dihapus")->success()->send();
                    $this->redirect(static::getUrl());
                }),
        ];
    }
}
