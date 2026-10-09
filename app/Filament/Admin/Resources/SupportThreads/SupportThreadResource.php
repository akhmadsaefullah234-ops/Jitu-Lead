<?php

namespace App\Filament\Admin\Resources\SupportThreads;

use App\Billing\PlatformStats;
use App\Filament\Admin\Resources\SupportThreads\Pages\ListSupportThreads;
use App\Filament\Admin\Resources\SupportThreads\Pages\ViewSupportThread;
use App\Models\SupportThread;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SupportThreadResource extends Resource
{
    protected static ?string $model = SupportThread::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $modelLabel = 'chat';

    protected static ?string $pluralModelLabel = 'Chat support';

    protected static ?string $navigationLabel = 'Chat support';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = PlatformStats::unansweredChats();

        return $n ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['tenant', 'user']))
            ->defaultSort('last_message_at', 'desc')
            ->columns([
                IconColumn::make('staff_unread')->label('Baru')->boolean()->trueIcon(Heroicon::Bell)->falseIcon('')->trueColor('danger'),
                TextColumn::make('tenant.name')->label('Agensi')->searchable(),
                TextColumn::make('user.name')->label('Pengguna')->placeholder('-'),
                TextColumn::make('subject')->label('Topik')->limit(60)->searchable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn ($state) => $state === SupportThread::OPEN ? 'Terbuka' : 'Selesai')
                    ->color(fn ($state) => $state === SupportThread::OPEN ? 'warning' : 'gray'),
                TextColumn::make('last_message_at')->label('Terakhir')->since(),
            ])
            ->filters([SelectFilter::make('status')->options([SupportThread::OPEN => 'Terbuka', SupportThread::CLOSED => 'Selesai'])->default(SupportThread::OPEN)])
            ->recordUrl(fn (SupportThread $r) => static::getUrl('view', ['record' => $r]))
            ->recordActions([Action::make('open')->label('Buka')->icon(Heroicon::OutlinedChatBubbleLeftRight)->url(fn (SupportThread $r) => static::getUrl('view', ['record' => $r]))]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportThreads::route('/'),
            'view' => ViewSupportThread::route('/{record}'),
        ];
    }
}
