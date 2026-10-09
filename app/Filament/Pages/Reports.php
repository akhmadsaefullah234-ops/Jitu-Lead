<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use App\Models\Stage;
use BackedEnum;
use Filament\Pages\Page;
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

    public const PERIODS = [7 => '7 hari', 30 => '30 hari', 90 => '90 hari', 365 => '1 tahun'];

    #[Url]
    public int $days = 30;

    private function leads(): Builder
    {
        $days = array_key_exists($this->days, self::PERIODS) ? $this->days : 30;

        return LeadResource::onlyVisibleLeads(Lead::query())->where('created_at', '>=', now()->subDays($days));
    }

    private function rate(int $part, int $whole): string
    {
        return $whole === 0 ? '0%' : round($part / $whole * 100, 1).'%';
    }

    public function getViewData(): array
    {
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
            'funnel' => $funnel, 'max' => $max, 'perAgent' => $perAgent, 'perSource' => $perSource, 'periods' => self::PERIODS,
        ];
    }
}
