<?php

namespace App\Filament\Admin\Pages;

use App\Models\PlatformSetting;
use App\Models\TrialEmailLog;
use App\Retention\TrialEmailSettings as Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** On/off and wording of each trial email, saved without a deploy. Empty fields fall back to the default text. */
class TrialEmailSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $navigationLabel = 'Email percobaan';

    protected static ?string $title = 'Email masa percobaan';

    protected static ?string $slug = 'trial-emails';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.admin.trial-email-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'enabled' => Settings::enabled(),
            'steps' => collect(Settings::steps())->map(fn (array $s) => ['button' => trim((string) $s['button'])] + array_intersect_key($s, array_flip(Settings::EDITABLE)))->all(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $sections = collect(config('trial_emails.steps'))->map(fn (array $default, string $key) => Section::make($default['label'])
            ->collapsible()->collapsed()
            ->description(fn () => ($n = TrialEmailLog::query()->where('step', $key)->where('status', TrialEmailLog::SENT)->count()) ? "Sudah terkirim ke $n agensi" : 'Belum pernah terkirim')
            ->schema([
                Toggle::make("steps.$key.enabled")->label('Kirim email ini'),
                TextInput::make("steps.$key.subject")->label('Subjek')->required()->maxLength(160),
                Textarea::make("steps.$key.body")->label('Isi')->required()->rows(9)->maxLength(5000)
                    ->helperText('Markdown sederhana: **tebal**, daftar "- " atau "1. ", baris kosong untuk paragraf baru.'),
                TextInput::make("steps.$key.button")->label('Tulisan tombol')->maxLength(60)->helperText('Kosongkan untuk tanpa tombol.'),
            ]))->values()->all();

        return $schema->statePath('data')->components([
            Section::make('Umum')->schema([
                Toggle::make('enabled')->label('Kirim email berseri masa percobaan')
                    ->helperText('Bila dimatikan, agensi yang sedang mencoba kembali mendapat satu email "segera berakhir" seperti sebelumnya.'),
            ]),
            ...$sections,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset')->label('Kembalikan teks bawaan')->color('gray')->requiresConfirmation()
                ->modalDescription('Semua subjek, isi, dan tombol kembali ke teks bawaan. Pilihan kirim/tidak tetap.')
                ->action('resetTexts'),
            Action::make('save')->label('Simpan')->action('save'),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();

        PlatformSetting::write('trial_emails', [
            'enabled' => (bool) ($state['enabled'] ?? false),
            'steps' => collect(config('trial_emails.steps'))->map(fn ($d, string $key) => [
                'enabled' => (bool) ($state['steps'][$key]['enabled'] ?? false),
                'subject' => trim((string) ($state['steps'][$key]['subject'] ?? '')),
                'body' => trim((string) ($state['steps'][$key]['body'] ?? '')),
                // An empty button is a choice, not a fallback to the default.
                'button' => trim((string) ($state['steps'][$key]['button'] ?? '')) ?: ' ',
            ])->all(),
        ]);

        Notification::make()->title('Tersimpan')->success()->send();
    }

    public function resetTexts(): void
    {
        $saved = Settings::saved();
        $saved['steps'] = collect((array) ($saved['steps'] ?? []))->map(fn ($s) => ['enabled' => (bool) ($s['enabled'] ?? true)])->all();
        PlatformSetting::write('trial_emails', $saved);
        $this->mount();

        Notification::make()->title('Teks bawaan dipulihkan')->success()->send();
    }
}
