<?php

namespace App\Filament\Pages;

use App\Ai\AnthropicClient;
use App\Enums\AiMode;
use App\Enums\Role;
use App\Models\WaChannel;
use App\Support\CurrentTenant;
use BackedEnum;
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

    protected static ?string $title = 'Mode AI per nomor WhatsApp';

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
                SelectColumn::make('ai_mode')->label('Mode')->options(AiMode::class)->selectablePlaceholder(false),
            ]);
    }
}
