<?php

namespace App\Filament\Resources\AiKnowledge;

use App\Filament\Resources\AiKnowledge\Pages\ManageAiKnowledge;
use App\Models\AiKnowledgeItem;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class AiKnowledgeResource extends Resource
{
    protected static ?string $model = AiKnowledgeItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $modelLabel = 'catatan pengetahuan';

    protected static ?string $pluralModelLabel = 'pengetahuan AI';

    protected static ?string $navigationLabel = 'Pengetahuan AI';

    protected static string|\UnitEnum|null $navigationGroup = 'AI Asisten';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('Judul')->required()->maxLength(120)->placeholder('Contoh: Harga Cluster Mawar, Cara KPR, Jam survei'),
            Textarea::make('question')->label('Pertanyaan klien (opsional)')->rows(2)->maxLength(500)
                ->helperText('Isi bila ini jawaban untuk satu pertanyaan tertentu.'),
            Textarea::make('content')->label('Isi / jawaban')->required()->rows(8)->maxLength(4000)
                ->helperText('Tulis apa adanya: harga, DP, cicilan, fasilitas, alamat, syarat KPR, jam survei. AI tidak akan menambah yang tidak tertulis.'),
            Toggle::make('active')->label('Dipakai AI')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('title')->label('Judul')->searchable()->description(fn (AiKnowledgeItem $r) => str($r->content)->limit(90)),
            TextColumn::make('source')->label('Asal')->badge()->formatStateUsing(fn ($state) => match ($state) {
                'learned' => 'Dipelajari',
                'upload' => 'Dokumen',
                default => 'Manual',
            }),
            ToggleColumn::make('active')->label('Dipakai'),
        ])->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('Belum ada pengetahuan')
            ->emptyStateDescription('Tambahkan harga, fasilitas, dan jawaban yang sering ditanyakan agar AI bisa membantu membalas chat.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageAiKnowledge::route('/')];
    }
}
