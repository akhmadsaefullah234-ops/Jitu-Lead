<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Subscription;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Trials that end soon or just ended: the best people to follow up with. */
class TrialsEnding extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Uji coba yang segera / baru berakhir';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Subscription::query()->with('tenant')->where('status', Subscription::TRIAL)
                ->whereBetween('trial_ends_at', [now()->subDays(7), now()->addDays(4)])->orderBy('trial_ends_at'))
            ->paginated(false)
            ->emptyStateHeading('Tidak ada uji coba yang akan berakhir')
            ->columns([
                TextColumn::make('tenant.name')->label('Agensi'),
                TextColumn::make('trial_ends_at')->label('Uji coba berakhir')->since(),
                TextColumn::make('tenant.users_count')->label('Pengguna')->state(fn (Subscription $r) => $r->tenant?->users()->count()),
            ]);
    }
}
