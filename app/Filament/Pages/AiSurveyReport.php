<?php

namespace App\Filament\Pages;

use App\Reports\AiSurveyData;
use App\Support\CurrentTenant;
use App\Support\Period;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * "AI dan Survei" (property agencies only): what the AI did with the period's new chats, how many surveys it
 * booked and how fast, why it handed chats to an agent, and the Meta events and WhatsApp labels each stage sent.
 * The numbers are App\Reports\AiSurveyData; the same period picker as the Dashboard and Laporan.
 */
class AiSurveyReport extends Page
{
    protected string $view = 'filament.pages.ai-survey-report';

    protected static ?string $slug = 'ai-dan-survei';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?string $navigationLabel = 'AI dan Survei';

    protected static ?string $title = 'AI dan Survei';

    protected static ?int $navigationSort = 11;

    #[Url]
    public ?string $periode = null;

    #[Url]
    public ?string $dari = null;

    #[Url]
    public ?string $sampai = null;

    public static function canAccess(): bool
    {
        $current = app(CurrentTenant::class);

        return $current->get()?->industry()->key === 'properti' && ($current->roleOf(auth()->user())?->seesAllLeads() ?? false);
    }

    public function mount(): void
    {
        if (! request()->hasAny(['periode', 'dari', 'sampai'])) {
            ['periode' => $this->periode, 'dari' => $this->dari, 'sampai' => $this->sampai] = Period::remembered('ai-survey');
        }

        $this->periode ??= '30d';
        $this->periodForm->fill(['periode' => $this->periode, 'dari' => $this->dari, 'sampai' => $this->sampai]);
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['periode', 'dari', 'sampai'], true)) {
            Period::remember('ai-survey', $this->periode, $this->dari, $this->sampai);
        }
    }

    public function periodForm(Schema $schema): Schema
    {
        return $schema->components(Period::fields());
    }

    public function period(): Period
    {
        return Period::make($this->periode, $this->dari, $this->sampai, default: '30d');
    }

    public function getViewData(): array
    {
        return ['data' => app(AiSurveyData::class)->summary($this->period()), 'period' => $this->period()];
    }
}
