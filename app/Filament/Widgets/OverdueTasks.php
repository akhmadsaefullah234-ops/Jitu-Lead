<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Widgets\Concerns\VisibleLeads;
use App\Models\Lead;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class OverdueTasks extends TableWidget
{
    use VisibleLeads;

    protected static ?int $sort = 4;

    protected static ?string $heading = 'Tindak lanjut yang harus dikerjakan';

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 1];

    public function table(Table $table): Table
    {
        return $table
            ->query($this->leads()->open()->whereNotNull('next_action_due_at')->where('next_action_due_at', '<', now()->endOfDay())->orderBy('next_action_due_at'))
            ->paginated([5])
            ->emptyStateHeading('Tidak ada tindak lanjut yang tertunda')
            ->columns([
                TextColumn::make('name')->label('Lead')->weight('bold')->wrap()->description(fn (Lead $r) => $r->next_action),
                TextColumn::make('next_action_due_at')->label('Batas')->since()
                    ->color(fn (Lead $r) => $r->isOverdue() ? 'danger' : 'warning'),
            ])
            ->recordUrl(fn (Lead $r) => LeadResource::getUrl('edit', ['record' => $r]));
    }
}
