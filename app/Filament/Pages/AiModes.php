<?php

namespace App\Filament\Pages;

use App\Ai\AnthropicClient;
use App\Ai\AutoGate;
use App\Enums\Role;
use App\Models\WaChannel;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * The switch between manual and AI, one per WhatsApp number.
 */
class AiModes extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.ai-modes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPower;

    protected static ?string $navigationLabel = 'Mode AI';

    protected static ?string $title = 'Siapa yang membalas chat';

    protected static string|\UnitEnum|null $navigationGroup = 'AI Asisten';

    protected static ?int $navigationSort = 0;

    public static function canAccess(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user()) === Role::Admin;
    }

    public function aiConfigured(): bool
    {
        return app(AnthropicClient::class)->configured();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('raiseToAuto')->label('Naikkan ke Auto')->icon(Heroicon::OutlinedRocketLaunch)
                ->visible(fn () => AutoGate::locked(app(CurrentTenant::class)->get()))
                ->disabled(fn () => AutoGate::missing() !== [])
                ->tooltip(fn () => AutoGate::missing() !== [] ? 'Belum siap: '.implode(' ', AutoGate::missing()) : null)
                ->requiresConfirmation()
                ->modalHeading('Naikkan AI ke mode otomatis?')
                ->modalDescription('Nomor yang sekarang "AI menyiapkan, agen yang kirim" akan dibalas AI secara otomatis. Chat yang tidak ada jawabannya di pengetahuan tetap diserahkan ke agen. Mode tiap nomor tetap bisa diganti di tabel ini.')
                ->action(function () {
                    $count = AutoGate::open(app(CurrentTenant::class)->get());
                    Notification::make()->title('AI membalas otomatis')->body($count.' nomor dinaikkan ke Auto.')->success()->send();
                }),
        ];
    }

    /** Why Auto is not offered yet, for the page; null once it is. */
    public function gateNote(): ?string
    {
        if (! AutoGate::locked(app(CurrentTenant::class)->get())) {
            return null;
        }

        $missing = AutoGate::missing();

        return 'Agensi baru dimulai dengan AI menyiapkan draf: agen memeriksa dan mengirim. Pilihan "AI membalas otomatis" terbuka lewat tombol Naikkan ke Auto'
            .($missing === [] ? ', yang sudah bisa dipakai.' : ' setelah data berikut lengkap: '.implode(' ', $missing));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => WaChannel::query())
            ->defaultSort('position')
            ->paginated(false)
            ->emptyStateHeading('Belum ada nomor WhatsApp')
            ->emptyStateDescription('Hubungkan nomor di menu Koneksi WhatsApp, lalu atur mode AI-nya di sini.')
            ->columns([
                TextColumn::make('name')->label('Nomor')->weight('bold')->description(fn (WaChannel $r) => $r->phone),
                TextColumn::make('type')->label('Jenis')->badge(),
                TextColumn::make('status')->label('Status')->badge()->color(fn ($state) => $state->getColor()),
                // A new property agency picks Auto only after "Naikkan ke Auto" (AutoGate).
                SelectColumn::make('ai_mode')->label('Pembalas')->options(fn () => AutoGate::options(app(CurrentTenant::class)->get()))->selectablePlaceholder(false),
            ]);
    }
}
