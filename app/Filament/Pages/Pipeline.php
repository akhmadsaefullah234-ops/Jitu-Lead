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
use App\Filament\Resources\Shipments\ShipmentResource;
use App\Reports\DashboardData;
use App\Shipping\Shipments\PackingState;
use App\Support\CurrentTenant;
use App\Support\Industry;
use App\Support\Period;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use App\Enums\Role;
use App\Models\Shipment;
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
        $valueSql = $retail ? DashboardData::ORDER_VALUE : 'coalesce(leads.deal_value, 0)';
        $values = $this->filteredLeads()->reorder()->groupBy('leads.stage_id')
            ->selectRaw('leads.stage_id, sum('.$valueSql.') as v')->pluck('v', 'stage_id');
        $split = $this->splitStageId();
        $columns = collect();

        foreach ($stages as $stage) {
            $inStage = $leads->get($stage->getKey(), collect());

            if ($stage->getKey() !== $split) {
                $columns->push([
                    'key' => (string) $stage->getKey(), 'stage' => $stage, 'part' => null, 'name' => $stage->name, 'hint' => $stage->default_action ?? 'Wajib pilih alasan',
                    'leads' => $this->sortCards($inStage)->take(self::CARDS_PER_STAGE), 'count' => $inStage->count(), 'value' => (int) ($values[$stage->getKey()] ?? 0),
                ]);

                continue;
            }

            // "Dikemas & dikirim" as two columns read from each lead's parcel; the lead's stage stays the same.
            $states = PackingState::forLeads($inStage);
            $perLead = $inStage->isEmpty() ? collect() : $this->filteredLeads()->reorder()->where('leads.stage_id', $stage->getKey())->groupBy('leads.id')
                ->selectRaw('leads.id, sum('.$valueSql.') as v')->pluck('v', 'id');

            foreach (['packed' => ['Dikemas', 'Order OK, resi belum dicetak'], 'sent' => ['Dikirim', 'Resi sudah dicetak']] as $part => [$name, $hint]) {
                $cards = $inStage->filter(fn (Lead $l) => $states[$l->getKey()]->packed() === ($part === 'packed'));
                $columns->push([
                    'key' => $stage->getKey().'-'.$part, 'stage' => $stage, 'part' => $part, 'name' => $name, 'hint' => $hint,
                    'leads' => $this->sortCards($cards)->take(self::CARDS_PER_STAGE), 'count' => $cards->count(),
                    'value' => (int) $cards->sum(fn (Lead $l) => (int) ($perLead[$l->getKey()] ?? 0)),
                ]);
            }
        }

        return [
            'columns' => $columns,
            'packing' => $split !== null ? PackingState::forLeads($leads->get($split, collect())) : [],
            'period' => $this->period(),
            'filters' => [
                'all' => 'Semua',
                'hot' => 'Hot',
                'today' => 'Aksi hari ini',
                'late' => 'Terlambat',
            ] + ($this->shipsParcels() ? ['resi' => 'Perlu resi', 'print' => 'Siap cetak', 'failed' => 'Gagal antar'] : []),
            'agents' => $this->canSeeTeam()
                ? app(CurrentTenant::class)->get()->activeUsers()->orderBy('name')->pluck('name', 'users.id')
                : collect(),
            'editUrl' => fn (Lead $lead) => LeadResource::getUrl('edit', ['record' => $lead]),
        ];
    }

    /** The stage shown as Dikemas and Dikirim, when the agency has it on (Pengaturan Pipeline). */
    public function splitStageId(): ?int
    {
        return PackingState::splitEnabled(app(CurrentTenant::class)->get()) ? PackingState::stageId() : null;
    }

    /** A shop that books parcels through a provider: the shipping filters are offered. */
    private function shipsParcels(): bool
    {
        return Industry::current()->key === 'retail' && \App\Models\CarrierAccount::query()->exists();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('settings')->label('Pengaturan Pipeline')->icon(Heroicon::OutlinedCog6Tooth)->color('gray')
                ->visible(fn () => Industry::current()->key === 'retail' && app(CurrentTenant::class)->roleOf(auth()->user()) === Role::Admin)
                ->fillForm(fn () => ['split_shipping' => PackingState::splitEnabled(app(CurrentTenant::class)->get())])
                ->schema([
                    Toggle::make('split_shipping')->label('Pisahkan Dikemas dan Dikirim')
                        ->helperText('Tahap "Dikemas & dikirim" tampil sebagai dua kolom menurut resi: Dikemas (belum ada resi atau belum dicetak) dan Dikirim (resi sudah dicetak). Tahap lead tidak berubah.'),
                ])
                ->modalSubmitActionLabel('Simpan')
                ->action(function (array $data) {
                    $tenant = app(CurrentTenant::class)->get();
                    $tenant->forceFill(['pipeline_settings' => array_merge((array) $tenant->pipeline_settings, ['split_shipping' => (bool) ($data['split_shipping'] ?? false)])])->save();
                    Notification::make()->success()->title('Pengaturan Pipeline disimpan')->send();
                }),
        ];
    }

    public function moveLead(int $leadId, int $stageId, ?string $part = null): void
    {
        $lead = $this->findLead($leadId);
        $stage = Stage::query()->findOrFail($stageId);

        if ($stage->is($lead->stage)) {
            if ($part !== null && $stage->getKey() === $this->splitStageId()) {
                $this->markPrinted($lead, $part === 'sent');
            }

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

    /** Dikemas -> Dikirim marks the label printed (like "Tandai sudah dicetak"); back again unmarks it. */
    private function markPrinted(Lead $lead, bool $printed): void
    {
        $state = PackingState::of($lead, Shipment::query()->where('lead_id', $lead->getKey())->where('state', '!=', Shipment::CANCELLED)->latest('id')->first());

        if ($state->packed() === ! $printed) {
            return;
        }

        if ($state->shipment === null || ! $state->shipment->printable()) {
            Notification::make()->warning()->title($printed ? 'Belum bisa ditandai dicetak' : 'Tanda cetak tidak bisa dibatalkan')
                ->body($printed ? 'Lead ini belum punya resi'.($state->reason ? ' ('.$state->reason.')' : '').'. Buat resi dulu.' : 'Resi ini tidak dicetak dari JITU LEAD.')->send();

            return;
        }

        if (! $printed && $state->phase !== PackingState::PRINTED) {
            Notification::make()->warning()->title('Paket sudah diambil kurir')->body('Status kurir: '.$state->courierStatus().'. Tanda cetak tidak dibatalkan.')->send();

            return;
        }

        ShipmentResource::mark(collect([$state->shipment]), $printed);
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
            ->when($this->filter === 'late', fn (Builder $q) => $q->overdue())
            ->when(in_array($this->filter, ['resi', 'print'], true), fn (Builder $q) => $q->whereIn('leads.id', $this->packingIds($this->filter === 'resi' ? PackingState::NEEDS_RESI : PackingState::READY)))
            ->when($this->filter === 'failed', fn (Builder $q) => $q->whereIn('leads.id', Shipment::query()->tab('action')->whereIn('status', [\App\Shipping\Carriers\ShipmentStatus::DELIVERY_FAILED])->select('lead_id')));
    }

    /** Leads of the shipping stage in one packing phase (filters "Perlu resi" and "Siap cetak"). */
    private function packingIds(string $phase): array
    {
        $stage = PackingState::stageId();
        $leads = $stage ? LeadResource::getEloquentQuery()->where('leads.stage_id', $stage)->get() : collect();

        return collect(PackingState::forLeads($leads))->filter(fn (PackingState $s) => $s->phase === $phase)->keys()->all();
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
