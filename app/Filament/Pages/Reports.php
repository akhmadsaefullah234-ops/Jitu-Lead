<?php

namespace App\Filament\Pages;

use App\Billing\PlanLimits;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use App\Models\Stage;
use App\Support\Period;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * Plain tables answering the questions a sales team asks every week: where
 * leads stall, who closes, and which source pays off.
 */
class Reports extends Page
{
    protected string $view = 'filament.pages.reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Laporan';

    protected static ?string $title = 'Laporan';

    protected static ?int $navigationSort = 10;

    /** The same period picker as the Dashboard, Lead and Pipeline (App\Support\Period); 30 days unless chosen. */
    #[Url]
    public ?string $periode = null;

    #[Url]
    public ?string $dari = null;

    #[Url]
    public ?string $sampai = null;

    public function mount(): void
    {
        if (! request()->hasAny(['periode', 'dari', 'sampai'])) {
            ['periode' => $this->periode, 'dari' => $this->dari, 'sampai' => $this->sampai] = Period::remembered('reports');
        }

        $this->periode ??= '30d';
        $this->periodForm->fill(['periode' => $this->periode, 'dari' => $this->dari, 'sampai' => $this->sampai]);
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['periode', 'dari', 'sampai'], true)) {
            Period::remember('reports', $this->periode, $this->dari, $this->sampai);
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

    private function leads(): Builder
    {
        return $this->period()->apply(LeadResource::onlyVisibleLeads(Lead::query()), 'leads.created_at');
    }

    private function rate(int $part, int $whole): string
    {
        return $whole === 0 ? '0%' : round($part / $whole * 100, 1).'%';
    }

    public function lockedReason(): ?string
    {
        return PlanLimits::current()?->denyFeature('reports');
    }

    public function getViewData(): array
    {
        if ($this->lockedReason()) {
            return ['locked' => true];
        }

        $leads = $this->leads()->with(['stage:id,type,name', 'source:id,name', 'owner:id,name'])->get();
        $won = fn ($group) => $group->filter(fn (Lead $l) => $l->stage?->type->value === 'won');

        $total = $leads->count();
        $closed = $won($leads);

        $funnel = Stage::query()->orderBy('position')->get()->map(fn (Stage $s) => [
            'name' => $s->name, 'type' => $s->type->value, 'count' => $leads->where('stage_id', $s->getKey())->count(),
        ]);
        $max = max(1, $funnel->max('count'));

        $perAgent = $leads->groupBy('owner_id')->map(fn ($g) => [
            'name' => $g->first()->owner?->name ?? 'Belum ditugaskan', 'leads' => $g->count(),
            'won' => $won($g)->count(), 'value' => (int) $won($g)->sum('deal_value'),
            'rate' => $this->rate($won($g)->count(), $g->count()),
        ])->sortByDesc('won')->values();

        $perSource = $leads->groupBy('lead_source_id')->map(fn ($g) => [
            'name' => $g->first()->source?->name ?? 'Tanpa sumber', 'leads' => $g->count(),
            'won' => $won($g)->count(), 'rate' => $this->rate($won($g)->count(), $g->count()),
        ])->sortByDesc('leads')->values();

        return [
            'summary' => [
                'Lead masuk' => number_format($total, 0, ',', '.'),
                'Closing' => number_format($closed->count(), 0, ',', '.'),
                'Konversi' => $this->rate($closed->count(), $total),
                'Nilai transaksi' => 'Rp '.number_format((int) $closed->sum('deal_value'), 0, ',', '.'),
            ],
            'funnel' => $funnel, 'max' => $max, 'perAgent' => $perAgent, 'perSource' => $perSource, 'period' => $this->period(),
        ];
    }
}
