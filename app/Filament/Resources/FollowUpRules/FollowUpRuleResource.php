<?php

namespace App\Filament\Resources\FollowUpRules;

use App\Filament\Resources\FollowUpRules\Pages\ManageFollowUpRules;
use App\Models\FollowUpRule;
use App\Models\Stage;
use App\WhatsApp\TemplateRenderer;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FollowUpRuleResource extends Resource
{
    protected static ?string $model = FollowUpRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $modelLabel = 'aturan follow-up';

    protected static ?string $pluralModelLabel = 'follow-up otomatis';

    protected static ?string $navigationLabel = 'Follow-up otomatis';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        $placeholders = collect(TemplateRenderer::PLACEHOLDERS)->map(fn ($p) => '{'.$p.'}')->implode(' ');

        return $schema->components([
            TextInput::make('name')->label('Nama aturan')->required()->maxLength(80)->placeholder('Contoh: Sapaan H+1'),
            Select::make('stage_id')->label('Berlaku di tahap')
                ->options(fn () => Stage::query()->where('type', 'open')->orderBy('position')->pluck('name', 'id'))
                ->placeholder('Semua tahap yang masih terbuka')
                ->helperText('Pesan dikirim sekali per lead di tahap ini. Lead yang pindah tahap tidak lagi menerimanya.'),
            TextInput::make('delay_hours')->label('Kirim setelah (jam)')->numeric()->required()->minValue(1)->maxValue(2160)
                ->helperText('Dihitung sejak lead masuk ke tahap. 24 jam = H+1, 72 jam = H+3.'),
            Textarea::make('body')->label('Isi pesan')->required()->rows(5)->maxLength(1000)
                ->helperText("Variabel yang bisa dipakai: $placeholders"),
            TextInput::make('send_from_hour')->label('Mulai kirim jam')->numeric()->required()->default(8)->minValue(0)->maxValue(23),
            TextInput::make('send_until_hour')->label('Berhenti kirim jam')->numeric()->required()->default(20)->minValue(1)->maxValue(24)
                ->gt('send_from_hour')
                ->helperText('Pesan tidak dikirim di luar jam ini, supaya klien tidak dihubungi malam hari.'),
            Toggle::make('active')->label('Aktif')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('delay_hours')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('stage')->withCount([
                'logs as sent_count' => fn ($q) => $q->where('status', 'sent'),
            ]))
            ->columns([
                TextColumn::make('name')->label('Aturan')->searchable()->description(fn (FollowUpRule $r) => str($r->body)->limit(70)),
                TextColumn::make('stage.name')->label('Tahap')->placeholder('Semua tahap terbuka'),
                TextColumn::make('delay_hours')->label('Setelah')->formatStateUsing(fn ($state) => $state % 24 === 0 ? 'H+'.($state / 24) : $state.' jam')->sortable(),
                TextColumn::make('sent_count')->label('Terkirim'),
                ToggleColumn::make('active')->label('Aktif'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('Belum ada aturan follow-up')
            ->emptyStateDescription('Tambahkan aturan agar pesan sapaan terkirim otomatis sesuai tahap pipeline.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageFollowUpRules::route('/')];
    }
}
