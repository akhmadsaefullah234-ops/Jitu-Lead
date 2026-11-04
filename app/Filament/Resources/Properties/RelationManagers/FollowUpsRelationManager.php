<?php

namespace App\Filament\Resources\Properties\RelationManagers;

use App\Billing\Deny;
use App\Billing\PlanLimits;
use App\Filament\Resources\FollowUpRules\FollowUpRuleResource;
use App\FollowUps\GeneralFollowUps;
use App\Models\FollowUpRule;
use App\Models\Property;
use App\Support\CurrentTenant;
use App\Support\Industry;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * This product's own follow-up sequence. For a stage it covers, a lead of this product gets only these steps, not
 * the general ones.
 */
class FollowUpsRelationManager extends RelationManager
{
    protected static string $relationship = 'followUpRules';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedBellAlert;

    /** @var array<int, string>|null rule id => "Order masuk 1/4" */
    private ?array $steps = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Follow-up';
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $active = $ownerRecord->followUpRules()->where('active', true)->count();

        return $active > 0 ? (string) $active : null;
    }

    public static function getBadgeTooltip(Model $ownerRecord, string $pageClass): ?string
    {
        return 'Aturan follow-up aktif';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(FollowUpRuleResource::fields(fn ($livewire) => $livewire->getOwnerRecord()));
    }

    public function table(Table $table): Table
    {
        $item = Industry::t('catalog_item');
        $product = $this->getOwnerRecord();

        return $table->recordTitleAttribute('name')
            ->heading('Follow-up khusus '.$item.' ini')
            ->description(fn () => $this->description())
            ->modifyQueryUsing(fn (Builder $query) => $query->with('stage')->withCount([
                'logs as sent_30d' => fn ($q) => $q->where('status', 'sent')->where('created_at', '>=', now()->subDays(30)),
            ]))
            ->defaultSort(fn (Builder $query) => $query->orderBy('delay_days')->orderBy('send_time')->orderBy('id'))
            // Stages in pipeline order, "all open stages" last.
            ->defaultGroup(Group::make('stage_id')->label('Tahap')->collapsible()
                ->orderQueryUsing(fn (Builder $query) => $query->orderByRaw('(select position from stages where stages.id = follow_up_rules.stage_id) asc nulls last'))
                ->getTitleFromRecordUsing(fn (FollowUpRule $r) => $r->stage?->name ?? 'Semua tahap terbuka'))
            ->groupingSettingsHidden()
            ->columns([
                TextColumn::make('step')->label('Langkah')->state(fn (FollowUpRule $r) => $this->steps()[$r->getKey()] ?? '')->badge()->color('gray'),
                TextColumn::make('name')->label('Aturan')->wrap()
                    ->description(fn (FollowUpRule $r) => str(FollowUpRuleResource::preview($r->body, $product))->after('Pratinjau: ')->limit(90)),
                TextColumn::make('delay_days')->label('Jadwal')->formatStateUsing(fn ($state, FollowUpRule $r) => "H+$state, jam {$r->send_time}"),
                ToggleColumn::make('active')->label('Aktif'),
                TextColumn::make('sent_30d')->label('Terkirim 30 hari')->alignCenter(),
            ])
            ->headerActions([
                ActionGroup::make([$this->copyGeneralAction(), $this->copyProductAction()])
                    ->label('Salin rangkaian')->icon(Heroicon::OutlinedDocumentDuplicate)->color('gray')->button(),
                CreateAction::make()->label('Tambah langkah')->before(function (CreateAction $action) {
                    if ($denied = PlanLimits::current()?->denyAdding('followups')) {
                        Deny::notify($denied);
                        $action->halt();
                    }
                }),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('Belum ada follow-up khusus')
            ->emptyStateDescription(fn () => (GeneralFollowUps::for(app(CurrentTenant::class)->get())->applies()
                ? "Lead {$item} ini memakai follow-up umum. "
                : '').'Tambah langkah, atau salin dari follow-up umum lalu sesuaikan isinya.');
    }

    /** What this sequence does, with a warning when this product's leads would get no follow-up at all. */
    private function description(): HtmlString
    {
        $product = $this->getOwnerRecord();
        $item = Industry::t('catalog_item');
        $general = GeneralFollowUps::for(app(CurrentTenant::class)->get())->applies();
        $text = e($general
            ? "Lead yang menanyakan {$product->name} menerima rangkaian ini, bukan follow-up umum, di tahap yang diaturnya. Tahap lain tetap memakai follow-up umum."
            : "Lead yang menanyakan {$product->name} hanya menerima rangkaian ini: follow-up umum sedang tidak dipakai.");

        if (! $general && $product->status !== 'sold' && ! $product->followUpRules()->where('active', true)->exists()) {
            $text = '<span class="fu-warn" style="display:block;margin-bottom:.35rem;padding:.45rem .7rem;border-radius:.5rem;background:#fef3c7;color:#92400e;font-weight:600">'
                .e("Lead {$item} ini belum akan menerima follow-up. Tambah langkah atau salin dari follow-up umum, lalu aktifkan.").'</span>'.$text;
        }

        return new HtmlString($text);
    }

    /** @return array<int, string> */
    private function steps(): array
    {
        if ($this->steps !== null) {
            return $this->steps;
        }

        $this->steps = [];
        $byStage = $this->getOwnerRecord()->followUpRules()->with('stage')
            ->orderBy('delay_days')->orderBy('send_time')->orderBy('id')->get()->groupBy('stage_id');

        foreach ($byStage as $rules) {
            foreach ($rules->values() as $i => $rule) {
                $this->steps[$rule->getKey()] = ($rule->stage?->name ?? 'Semua tahap').' '.($i + 1).'/'.$rules->count();
            }
        }

        return $this->steps;
    }

    private function copyGeneralAction(): Action
    {
        return Action::make('copyGeneral')->label('Salin dari follow-up umum')->icon(Heroicon::OutlinedGlobeAlt)
            ->requiresConfirmation()
            ->modalDescription('Semua aturan follow-up umum disalin ke '.Industry::t('catalog_item').' ini dalam keadaan NONAKTIF. Sesuaikan isinya, lalu aktifkan.')
            ->action(fn (Action $action) => $this->copy(FollowUpRule::query()->whereNull('property_id')->whereNull('kind')->get(), $action));
    }

    private function copyProductAction(): Action
    {
        $product = $this->getOwnerRecord();

        return Action::make('copyProduct')->label('Salin dari '.Industry::t('catalog_item').' lain')->icon(Heroicon::OutlinedSquares2x2)
            ->modalDescription('Aturan '.Industry::t('catalog_item').' yang dipilih disalin ke sini dalam keadaan NONAKTIF.')
            ->modalSubmitActionLabel('Salin')
            ->schema([
                Select::make('from')->label('Salin dari')->required()->searchable()
                    ->options(fn () => Property::query()->whereKeyNot($product->getKey())->whereHas('followUpRules')
                        ->withCount('followUpRules')->orderBy('name')->get()
                        ->mapWithKeys(fn (Property $p) => [$p->getKey() => "{$p->name} ({$p->follow_up_rules_count} langkah)"])),
            ])
            ->action(fn (array $data, Action $action) => $this->copy(
                FollowUpRule::query()->where('property_id', $data['from'])->where('property_id', '!=', $product->getKey())->get(),
                $action,
            ));
    }

    /** Copies the rules to this product, switched off, within the plan's total. */
    private function copy(Collection $rules, Action $action): void
    {
        if ($rules->isEmpty()) {
            Notification::make()->title('Tidak ada aturan untuk disalin')->warning()->send();

            return;
        }

        $limits = PlanLimits::current();

        if ($denied = $limits?->denyAdding('followups')) {
            Deny::notify($denied);
            $action->halt();
        }

        $limit = $limits?->limit('followups');

        if ($limit !== null && $limits->usage('followups') + $rules->count() > $limit) {
            Deny::notify("Batas follow-up paket ini {$limit} aturan (dihitung total, umum dan semua ".Industry::t('catalog_item').'). Sisa '.max(0, $limit - $limits->usage('followups')).", sedangkan yang akan disalin {$rules->count()}.");
            $action->halt();
        }

        $product = $this->getOwnerRecord();

        foreach ($rules as $rule) {
            $product->followUpRules()->create([
                ...$rule->only(['name', 'stage_id', 'delay_days', 'send_time', 'body', 'media_asset_id']),
                'active' => false,
            ]);
        }

        $this->steps = null;
        Notification::make()->title($rules->count().' langkah disalin, masih nonaktif')
            ->body('Periksa isi pesannya, lalu aktifkan langkah yang ingin dipakai.')->success()->send();
    }
}
