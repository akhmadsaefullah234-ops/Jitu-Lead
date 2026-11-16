<?php

namespace App\Filament\Pages;

use App\Automation\PropertyStageAutomation;
use App\Automation\PropertyStageAutomationSettings;
use App\Automation\StageAutomationSettings;
use App\Enums\Role;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Which automatic stage moves an agency uses: a shop's (App\Automation\StageAutomation) or a property agency's
 * (App\Automation\PropertyStageAutomation), each with its own rules. A property agency made before its rules
 * existed sees them off with a "Nyalakan" button; nothing is switched on without an admin.
 */
class StageAutomationPage extends Page
{
    protected string $view = 'filament.pages.stage-automation';

    protected static ?string $slug = 'otomatisasi-tahap';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static ?string $navigationLabel = 'Otomatisasi tahap';

    protected static ?string $title = 'Otomatisasi tahap';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 14;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $current = app(CurrentTenant::class);

        return $current->roleOf(auth()->user()) === Role::Admin
            && (StageAutomationSettings::appliesTo($current->get()) || PropertyStageAutomationSettings::appliesTo($current->get()));
    }

    public function isProperty(): bool
    {
        return PropertyStageAutomationSettings::appliesTo(app(CurrentTenant::class)->get());
    }

    /** A property agency that never switched its rules on. */
    public function waitingToStart(): bool
    {
        return $this->isProperty() && ! PropertyStageAutomationSettings::of(app(CurrentTenant::class)->get())->chosen();
    }

    public function mount(): void
    {
        $tenant = app(CurrentTenant::class)->get();
        $this->form->fill($this->isProperty() ? PropertyStageAutomationSettings::of($tenant)->toArray() : StageAutomationSettings::of($tenant)->toArray());
    }

    public function form(Schema $schema): Schema
    {
        $rules = $this->isProperty() ? PropertyStageAutomationSettings::RULES : StageAutomationSettings::RULES;
        $sections = [];
        $number = 0;

        foreach ($rules as $rule => [$label, $help, $numbers]) {
            $number++;
            $fields = [Toggle::make("{$rule}.enabled")->label('Aktif')->live()];

            foreach ($numbers as $key => $setting) {
                $fields[] = TextInput::make("{$rule}.{$key}")->label($setting[0])->numeric()->integer()->minValue(1)->maxValue(365)->suffix($setting[2] ?? 'hari')
                    ->required()->visible(fn (Get $get) => (bool) $get("{$rule}.enabled"));
            }

            if ($this->isProperty() && $rule === 'thanks') {
                $fields[] = Textarea::make('thanks.message')->label('Isi pesan')->rows(3)->maxLength(1000)
                    ->helperText('Bisa memakai {nama}, {agensi}, dan {properti}.')->visible(fn (Get $get) => (bool) $get('thanks.enabled'));
            }

            $sections[] = Section::make("{$number}. {$label}")->description($help)->columns(count($numbers) ? 3 : 1)->schema($fields)->compact();
        }

        return $schema->statePath('data')->components($sections)->disabled(fn () => $this->waitingToStart());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('start')->label('Nyalakan')->icon(Heroicon::OutlinedPlay)
                ->visible(fn () => $this->waitingToStart())
                ->requiresConfirmation()->modalHeading('Nyalakan otomatisasi tahap?')
                ->modalDescription('Semua aturan di halaman ini menyala dengan nilai bawaannya, kecuali pesan terima kasih setelah survei. Lead yang sudah ada tidak diubah sekarang; untuk itu pakai "Terapkan ke lead yang ada" setelah ini.')
                ->action(function () {
                    PropertyStageAutomationSettings::store(app(CurrentTenant::class)->get(), PropertyStageAutomationSettings::defaults());
                    $this->mount();
                    Notification::make()->title('Otomatisasi tahap menyala')->success()->send();
                }),
            Action::make('catchUp')->label('Terapkan ke lead yang ada')->color('gray')->icon(Heroicon::OutlinedQueueList)
                ->visible(fn () => $this->isProperty() && ! $this->waitingToStart())
                ->modalHeading('Terapkan ke lead yang ada')
                ->modalDescription(fn () => $this->preview())
                ->modalSubmitActionLabel('Terapkan')
                ->action(function () {
                    $done = app(PropertyStageAutomation::class)->catchUp(app(CurrentTenant::class)->get(), apply: true);
                    Notification::make()->title(count($done).' perubahan diterapkan')->success()->send();
                }),
            Action::make('save')->label('Simpan')->action('save')->visible(fn () => ! $this->waitingToStart()),
        ];
    }

    /** What "Terapkan" would change, listed before anything is changed. */
    private function preview(): HtmlString
    {
        $changes = app(PropertyStageAutomation::class)->catchUp(app(CurrentTenant::class)->get(), apply: false);

        if ($changes === []) {
            return new HtmlString('Tidak ada lead yang perlu diubah menurut aturan yang menyala.');
        }

        $rows = collect($changes)->take(50)->map(fn (array $c) => '<li><b>'.e($c['lead']).'</b>: '.e($c['change']).' ('.e($c['reason']).')</li>')->implode('');
        $more = count($changes) > 50 ? '<p>dan '.(count($changes) - 50).' lainnya.</p>' : '';

        return new HtmlString('<p>'.count($changes).' perubahan berikut akan diterapkan. Setiap perpindahan tercatat di aktivitas lead sebagai "Pindah otomatis".</p><ul style="list-style:disc;padding-left:1.25rem;margin-top:.5rem">'.$rows.'</ul>'.$more);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $tenant = app(CurrentTenant::class)->get();

        $this->isProperty()
            ? PropertyStageAutomationSettings::store($tenant, PropertyStageAutomationSettings::clean($state))
            : $tenant->forceFill(['stage_automation' => StageAutomationSettings::clean($state)])->save();

        Notification::make()->title('Otomatisasi tahap disimpan')->success()->send();
    }
}
