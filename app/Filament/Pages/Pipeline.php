<?php

namespace App\Filament\Pages;

use App\Actions\MoveLeadToStage;
use App\Enums\Interest;
use App\Enums\StageRequirement;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Schemas\LeadForm;
use App\Models\Lead;
use App\Models\LostReason;
use App\Models\Property;
use App\Models\Stage;
use App\Reports\DashboardData;
use App\Support\CurrentTenant;
use App\Support\Industry;
use App\Support\Period;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * The kanban board (PRD F3): leads grouped by stage, each card showing its
 * interest level and next action, overdue cards first.
 */
class Pipeline extends Page
{
    protected string $view = 'filament.pages.pipeline';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static ?string $navigationLabel = 'Pipeline';

    protected static ?string $title = 'Pipeline penjualan';

    protected static ?int $navigationSort = 1;

    public const CARDS_PER_STAGE = 50;

    #[Url]
    public string $search = '';

    #[Url]
    public string $filter = 'all';

    #[Url]
    public ?int $agent = null;

    /** Period (App\Support\Period) the board is limited to; "all" shows every lead. */
    #[Url]
    public ?string $periode = null;

    #[Url]
    public ?string $dari = null;

    #[Url]
    public ?string $sampai = null;

    /** Which date the period looks at: when the lead came in ("lead") or when it entered its stage ("tahap"). */
    #[Url]
    public string $dasar = 'lead';

    #[Url]
    public ?string $produk = null;

    public function mount(): void
    {
        // The viewer's last choice, unless the link carries one.
        if (! request()->hasAny(['periode', 'dari', 'sampai'])) {
            ['periode' => $this->periode, 'dari' => $this->dari, 'sampai' => $this->sampai] = Period::remembered('pipeline');
        }

        $this->periode ??= Period::ALL;
        $this->periodForm->fill(['periode' => $this->periode, 'dari' => $this->dari, 'sampai' => $this->sampai, 'dasar' => $this->dasar, 'produk' => $this->produk]);
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['periode', 'dari', 'sampai'], true)) {
            Period::remember('pipeline', $this->periode, $this->dari, $this->sampai);
        }
    }

    public function periodForm(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'md' => 2])->components([
            ...Period::fields(all: true),
            ToggleButtons::make('dasar')->label('Berdasarkan')->inline()->grouped()->live()
                ->options(['lead' => 'Tanggal lead masuk', 'tahap' => 'Tanggal masuk tahap'])
                ->visible(fn () => $this->period() !== null),
            Select::make('produk')->label(ucfirst(Industry::t('catalog_item')))->placeholder('Semua '.Industry::t('catalog_item'))->live()->searchable()
                ->visible(fn () => LeadForm::showsIndustryFields())
                ->options(fn () => ['none' => 'Tanpa '.Industry::t('catalog_item')] + Property::query()->orderBy('name')->pluck('name', 'id')->all()),
        ]);
    }

    public function period(): ?Period
    {
        return Period::optional($this->periode, $this->dari, $this->sampai);
    }

    public function getViewData(): array
    {
        $stages = Stage::query()->orderBy('position')->get();

        $leads = $this->filteredLeads()
            ->with(['owner:id,name', 'property:id,name'])
            ->get()
            ->groupBy('stage_id');
        // A shop counts what its orders are worth; a property agency the agreed deal values.
        $retail = Industry::current()->key === 'retail';
        $values = $this->filteredLeads()->reorder()->groupBy('leads.stage_id')
            ->selectRaw('leads.stage_id, sum('.($retail ? DashboardData::ORDER_VALUE : 'coalesce(leads.deal_value, 0)').') as v')->pluck('v', 'stage_id');

        return [
            'columns' => $stages->map(fn (Stage $stage) => [
                'stage' => $stage,
                'leads' => $this->sortCards($leads->get($stage->getKey(), collect()))->take(self::CARDS_PER_STAGE),
                'count' => $leads->get($stage->getKey(), collect())->count(),
                'value' => (int) ($values[$stage->getKey()] ?? 0),
            ]),
            'period' => $this->period(),
            'filters' => [
                'all' => 'Semua',
                'hot' => 'Hot',
                'today' => 'Aksi hari ini',
                'late' => 'Terlambat',
            ],
            'agents' => $this->canSeeTeam()
                ? app(CurrentTenant::class)->get()->activeUsers()->orderBy('name')->pluck('name', 'users.id')
                : collect(),
            'editUrl' => fn (Lead $lead) => LeadResource::getUrl('edit', ['record' => $lead]),
        ];
    }

    public function moveLead(int $leadId, int $stageId): void
    {
        $lead = $this->findLead($leadId);
        $stage = Stage::query()->findOrFail($stageId);

        if ($stage->is($lead->stage)) {
            return;
        }

        if ($stage->requirement !== null) {
            $this->mountAction('move', ['lead' => $leadId, 'stage' => $stageId]);

            return;
        }

        $this->move($lead, $stage, []);
    }

    public function moveAction(): Action
    {
        return Action::make('move')
            ->label('Pindah tahap')
            ->modalHeading(fn (array $arguments) => 'Pindahkan '.($this->findLead($arguments['lead'])->name))
            ->modalSubmitActionLabel('Pindahkan')
            ->fillForm(fn (array $arguments) => [
                'stage_id' => $arguments['stage'] ?? $this->findLead($arguments['lead'])->stage_id,
                'survey_location' => $this->findLead($arguments['lead'])->property?->name,
                'deal_value' => $this->findLead($arguments['lead'])->deal_value ?? $this->findLead($arguments['lead'])->budget_max,
                'unit' => $this->findLead($arguments['lead'])->unit,
            ])
            ->schema([
                Select::make('stage_id')
                    ->label('Tahap')
                    ->options(fn () => Stage::query()->orderBy('position')->pluck('name', 'id'))
                    ->required()
                    ->live(),
                DateTimePicker::make('survey_at')
                    ->label('Jadwal '.mb_strtolower(Industry::t('visit')))
                    ->seconds(false)
                    ->minDate(now())
                    ->required()
                    ->visible(fn (Get $get) => $this->requirementOf($get('stage_id')) === StageRequirement::Survey),
                TextInput::make('survey_location')
                    ->label('Lokasi '.mb_strtolower(Industry::t('visit')))
                    ->required()
                    ->maxLength(200)
                    ->visible(fn (Get $get) => $this->requirementOf($get('stage_id')) === StageRequirement::Survey),
                TextInput::make('unit')
                    ->label(Industry::t('deal_item'))
                    ->placeholder(Industry::t('deal_item_hint'))
                    ->required()
                    ->maxLength(120)
                    ->visible(fn (Get $get) => $this->requirementOf($get('stage_id')) === StageRequirement::Booking),
                TextInput::make('deal_value')
                    ->label('Nilai transaksi')
                    ->prefix('Rp')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->visible(fn (Get $get) => $this->requirementOf($get('stage_id')) === StageRequirement::Booking),
                Select::make('lost_reason_id')
                    ->label('Alasan gugur')
                    ->options(fn () => LostReason::query()->orderBy('label')->pluck('label', 'id'))
                    ->required()
                    ->visible(fn (Get $get) => $this->requirementOf($get('stage_id')) === StageRequirement::LostReason),
            ])
            ->action(function (array $data, array $arguments) {
                $this->move(
                    $this->findLead($arguments['lead']),
                    Stage::query()->findOrFail($data['stage_id']),
                    $data,
                );
            });
    }

    private function move(Lead $lead, Stage $stage, array $details): void
    {
        try {
            app(MoveLeadToStage::class)($lead, $stage, auth()->user(), $details);
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Lead belum bisa dipindah')->body(collect($e->errors())->flatten()->join(' '))->send();

            return;
        }

        Notification::make()
            ->success()
            ->title("{$lead->name} dipindah ke {$stage->name}")
            ->body($lead->next_action ? 'Aksi berikutnya diisi otomatis: '.$lead->next_action : null)
            ->send();
    }

    private function findLead(int $id): Lead
    {
        $lead = LeadResource::getEloquentQuery()->with('stage')->findOrFail($id);

        Gate::authorize('update', $lead);

        return $lead;
    }

    private function requirementOf(mixed $stageId): ?StageRequirement
    {
        return filled($stageId) ? Stage::query()->find($stageId)?->requirement : null;
    }

    private function filteredLeads(): Builder
    {
        $period = $this->period();

        return LeadResource::getEloquentQuery()
            ->when($period, fn (Builder $q) => $period->apply($q, $this->dasar === 'tahap' ? 'leads.stage_entered_at' : 'leads.created_at'))
            ->when(filled($this->produk), fn (Builder $q) => $this->produk === 'none' ? $q->whereNull('leads.property_id') : $q->where('leads.property_id', (int) $this->produk))
            ->when($this->agent && $this->canSeeTeam(), fn (Builder $q) => $q->where('owner_id', $this->agent))
            ->when(filled($this->search), function (Builder $q) {
                $term = '%'.mb_strtolower(trim($this->search)).'%';
                $q->where(fn (Builder $w) => $w
                    ->whereRaw('lower(name) like ?', [$term])
                    ->orWhere('phone', 'like', '%'.preg_replace('/\D+/', '', $this->search).'%')
                    ->orWhereRaw('lower(email) like ?', [$term]));
            })
            ->when($this->filter === 'hot', fn (Builder $q) => $q->where('interest', Interest::Hot))
            ->when($this->filter === 'today', fn (Builder $q) => $q->open()->whereBetween('next_action_due_at', [now(), now()->endOfDay()]))
            ->when($this->filter === 'late', fn (Builder $q) => $q->overdue());
    }

    /**
     * Overdue cards first, then by due date; cards with no due date last.
     */
    private function sortCards(Collection $leads): Collection
    {
        return $leads->sortBy(fn (Lead $lead) => [
            $lead->isOverdue() ? 0 : 1,
            $lead->next_action_due_at?->getTimestamp() ?? PHP_INT_MAX,
        ])->values();
    }

    private function canSeeTeam(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user())?->seesAllLeads() ?? false;
    }
}
