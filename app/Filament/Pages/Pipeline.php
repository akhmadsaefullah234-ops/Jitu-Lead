<?php

namespace App\Filament\Pages;

use App\Actions\MoveLeadToStage;
use App\Enums\Interest;
use App\Enums\StageRequirement;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use App\Models\LostReason;
use App\Models\Stage;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
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

    public function getViewData(): array
    {
        $stages = Stage::query()->orderBy('position')->get();

        $leads = $this->filteredLeads()
            ->with(['owner:id,name', 'property:id,name'])
            ->get()
            ->groupBy('stage_id');

        return [
            'columns' => $stages->map(fn (Stage $stage) => [
                'stage' => $stage,
                'leads' => $this->sortCards($leads->get($stage->getKey(), collect()))->take(self::CARDS_PER_STAGE),
                'count' => $leads->get($stage->getKey(), collect())->count(),
            ]),
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
                    ->label('Jadwal survei')
                    ->seconds(false)
                    ->minDate(now())
                    ->required()
                    ->visible(fn (Get $get) => $this->requirementOf($get('stage_id')) === StageRequirement::Survey),
                TextInput::make('survey_location')
                    ->label('Lokasi survei')
                    ->required()
                    ->maxLength(200)
                    ->visible(fn (Get $get) => $this->requirementOf($get('stage_id')) === StageRequirement::Survey),
                TextInput::make('unit')
                    ->label('Unit')
                    ->placeholder('Contoh: Blok C-12')
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
        return LeadResource::getEloquentQuery()
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
