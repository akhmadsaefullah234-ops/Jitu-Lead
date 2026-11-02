<?php

namespace App\Filament\Pages;

use App\Automation\StageAutomationSettings;
use App\Enums\Role;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Which automatic stage moves a shop uses and after how many days (App\Automation\StageAutomation).
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

        return $current->roleOf(auth()->user()) === Role::Admin && StageAutomationSettings::appliesTo($current->get());
    }

    public function mount(): void
    {
        $this->form->fill(StageAutomationSettings::of(app(CurrentTenant::class)->get())->toArray());
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];
        $number = 0;

        foreach (StageAutomationSettings::RULES as $rule => [$label, $help, $days]) {
            $number++;
            $fields = [Toggle::make("{$rule}.enabled")->label('Aktif')->live()];

            foreach ($days as $key => [$dayLabel]) {
                $fields[] = TextInput::make("{$rule}.{$key}")->label($dayLabel)->numeric()->integer()->minValue(1)->maxValue(365)->suffix('hari')
                    ->required()->visible(fn (Get $get) => (bool) $get("{$rule}.enabled"));
            }

            $sections[] = Section::make("{$number}. {$label}")->description($help)->columns(count($days) ? 3 : 1)->schema($fields)->compact();
        }

        return $schema->statePath('data')->components($sections);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('save')->label('Simpan')->action('save')];
    }

    public function save(): void
    {
        $state = $this->form->getState();
        app(CurrentTenant::class)->get()->forceFill(['stage_automation' => StageAutomationSettings::clean($state)])->save();

        Notification::make()->title('Otomatisasi tahap disimpan')->success()->send();
    }
}
