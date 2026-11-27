<?php

namespace App\Filament\Pages;

use App\Ai\AiReadiness;
use App\Ai\AiSettings;
use App\Ai\AnthropicClient;
use App\Filament\Concerns\InSettingsAndTools;
use App\Jobs\RunAiCheck;
use App\Models\AiEvaluation;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * "Kesehatan AI": how ready the property agency's AI is ("Kesiapan AI", App\Ai\AiReadiness), how it scored in the
 * last evaluations, and "Uji AI saya" (ten test questions, nothing sent to anyone). "Uji otomatis" runs the full
 * evaluation after each knowledge change and every night, and admins are e-mailed when the score drops.
 */
class AiHealthPage extends Page
{
    use InSettingsAndTools;

    protected string $view = 'filament.pages.ai-health';

    protected static ?string $slug = 'kesehatan-ai';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?string $navigationLabel = 'Kesehatan AI';

    protected static ?string $title = 'Kesehatan AI';

    // Opened from "Pengaturan & Alat" (App\Filament\Support\SettingsCatalog), not from the sidebar.
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return PropertySetup::canAccess();
    }

    public function readiness(): array
    {
        return AiReadiness::check();
    }

    public function latest(): ?AiEvaluation
    {
        return AiEvaluation::query()->latest('id')->first();
    }

    /** @return Collection<int, AiEvaluation> */
    public function history(): Collection
    {
        return AiEvaluation::query()->latest('id')->limit(10)->get();
    }

    public function running(): bool
    {
        return Cache::has(RunAiCheck::runningKey(app(CurrentTenant::class)->id()));
    }

    public function autoEval(): bool
    {
        return AiSettings::of(app(CurrentTenant::class)->get())->autoEval();
    }

    public function aiConfigured(): bool
    {
        return app(AnthropicClient::class)->configured();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('check')->label('Uji AI saya')->icon(Heroicon::OutlinedBeaker)
                ->disabled(fn () => $this->running() || ! $this->aiConfigured())
                ->requiresConfirmation()->modalHeading('Uji AI dengan 10 pertanyaan?')
                ->modalDescription('AI menjawab 10 pertanyaan klien contoh dari data Anda sendiri dan dari playbook. Tidak ada pesan yang dikirim ke siapa pun dan tidak ada chat yang tersimpan. Hasilnya muncul di halaman ini dalam satu sampai dua menit.')
                ->action(function () {
                    $id = app(CurrentTenant::class)->id();
                    Cache::put(RunAiCheck::runningKey($id), true, now()->addMinutes(15));
                    RunAiCheck::dispatch($id);
                    Notification::make()->title('Uji AI berjalan')->body('Hasilnya muncul di halaman ini sebentar lagi.')->success()->send();
                }),
            Action::make('autoEval')->label(fn () => $this->autoEval() ? 'Matikan uji otomatis' : 'Nyalakan uji otomatis')->color('gray')
                ->icon(fn () => $this->autoEval() ? Heroicon::OutlinedPause : Heroicon::OutlinedPlay)
                ->requiresConfirmation()
                ->modalDescription(fn () => $this->autoEval()
                    ? 'AI tidak lagi diuji sendiri setelah pengetahuan berubah dan tiap malam. "Uji AI saya" tetap bisa dipakai.'
                    : 'AI diuji lengkap (playbook dan pertanyaan dari data Anda) sekitar 10 menit setelah pengetahuan berubah dan tiap malam. Admin menerima email bila nilainya turun. Setiap uji memakai kuota layanan AI server.')
                ->action(function () {
                    $tenant = app(CurrentTenant::class)->get();
                    $tenant->forceFill(['ai_settings' => array_merge((array) $tenant->ai_settings, ['auto_eval' => ! $this->autoEval()])])->save();
                    Notification::make()->title($this->autoEval() ? 'Uji otomatis menyala' : 'Uji otomatis dimatikan')->success()->send();
                }),
        ];
    }
}
