<?php

namespace App\Filament\Pages;

use App\Actions\DemoData;
use App\Enums\Role;
use App\Filament\Widgets\BannerSlider;
use App\Filament\Widgets\Dashboard\AdPerformance;
use App\Filament\Widgets\Dashboard\AgentPerformance;
use App\Filament\Widgets\Dashboard\AutomationActivity;
use App\Filament\Widgets\Dashboard\CurrentStatus;
use App\Filament\Widgets\Dashboard\LeadFunnel;
use App\Filament\Widgets\Dashboard\PeriodStats;
use App\Filament\Widgets\Dashboard\ProductPerformance;
use App\Filament\Widgets\GettingStarted;
use App\Filament\Widgets\LeadsBySource;
use App\Filament\Widgets\OverdueTasks;
use App\Filament\Widgets\RecentLeads;
use App\Filament\Widgets\WhatsAppPrompt;
use App\Support\CurrentTenant;
use App\Support\Industry;
use App\Support\Period;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Everything the agency does, for one period picked at the top (App\Support\Period; 7 days unless the viewer
 * picked another, remembered in their session and shareable as ?periode=7d or ?dari=...&sampai=...). Every
 * widget follows it through the page filters, except "Status saat ini".
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $navigationLabel = 'Dasbor';

    protected static ?string $title = 'Dasbor';

    protected static ?int $navigationSort = 0;

    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }

    public function getWidgets(): array
    {
        return [
            GettingStarted::class,
            PeriodStats::class, CurrentStatus::class, LeadFunnel::class, LeadsBySource::class,
            ProductPerformance::class, AdPerformance::class, AutomationActivity::class, AgentPerformance::class,
            OverdueTasks::class, RecentLeads::class,
        ];
    }

    /** Above the period picker: the WhatsApp number prompt and the banner strip, so a phone sees them first. */
    protected function getHeaderWidgets(): array
    {
        return [WhatsAppPrompt::class, BannerSlider::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function mount(): void
    {
        // A shared link: ?periode=30d, or ?dari=2026-11-01&sampai=2026-11-04.
        $query = request()->only(['periode', 'dari', 'sampai']);

        if (filled($query['periode'] ?? null) || (filled($query['dari'] ?? null) && filled($query['sampai'] ?? null))) {
            $period = Period::make($query['periode'] ?? Period::CUSTOM, $query['dari'] ?? null, $query['sampai'] ?? null);
            $this->filters = $period->toQuery() + ['dari' => null, 'sampai' => null];
            $this->getFiltersForm()->fill($this->filters);
            session()->put($this->getFiltersSessionKey(), $this->filters);
        }
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->columns(1)->components(Period::fields(compare: true));
    }

    public function period(): Period
    {
        $f = (array) $this->filters;

        return Period::make($f['periode'] ?? null, $f['dari'] ?? null, $f['sampai'] ?? null);
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
                ->modalDescription('Membuat sekitar 30 lead dan 4 '.Industry::t('catalog_item').' contoh agar dasbor dan pipeline terlihat berjalan. Bisa dihapus kapan saja tanpa menyentuh data asli Anda.')
                ->action(function () use ($demo) {
                    $count = $demo->load();
                    Notification::make()->title("{$count} lead contoh dibuat")->success()->send();
                    $this->redirect(static::getUrl());
                }),
            Action::make('clearDemo')->label('Hapus data contoh')->icon(Heroicon::OutlinedTrash)->color('gray')
                ->visible(fn () => $this->isAdmin() && $demo->hasDemo())
                ->requiresConfirmation()->modalHeading('Hapus semua data contoh?')
                ->modalDescription('Hanya lead dan '.Industry::t('catalog_item').' bertanda contoh yang dihapus. Data yang Anda masukkan sendiri tetap aman.')
                ->action(function () use ($demo) {
                    $count = $demo->clear();
                    Notification::make()->title("{$count} lead contoh dihapus")->success()->send();
                    $this->redirect(static::getUrl());
                }),
        ];
    }
}
