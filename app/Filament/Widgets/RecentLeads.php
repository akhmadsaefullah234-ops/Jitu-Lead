<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Widgets\Concerns\VisibleLeads;
use App\Models\Lead;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentLeads extends TableWidget
{
    use VisibleLeads;

    protected static ?int $sort = 5;

    protected static ?string $heading = 'Lead terbaru';

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 1];

    public function table(Table $table): Table
    {
        return $table
            ->query($this->leads()->with(['stage', 'source'])->latest())
            ->paginated([5])
            ->emptyStateHeading('Belum ada lead')
            ->columns([
                TextColumn::make('name')->label('Lead')->weight('bold')->wrap()->description(fn (Lead $r) => $r->source?->name),
                TextColumn::make('stage.name')->label('Tahap')->badge(),
                TextColumn::make('interest')->label('Minat')->badge(),
            ])
            ->recordUrl(fn (Lead $r) => LeadResource::getUrl('edit', ['record' => $r]));
    }
}
