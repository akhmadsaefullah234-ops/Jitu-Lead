<?php

namespace App\Filament\Resources\AiKnowledge;

use App\Filament\Resources\AiKnowledge\Pages\ManageAiKnowledge;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\Properties\PropertyResource;
use App\Models\AiKnowledgeItem;
use App\Support\Industry;
use BackedEnum;
use Filament\Actions\Action;
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
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
            TextInput::make('title')->label('Judul')->required()->maxLength(120)->placeholder(Industry::t('knowledge_title_hint')),
            Textarea::make('question')->label('Pertanyaan klien (opsional)')->rows(2)->maxLength(500)
                ->helperText('Isi bila ini jawaban untuk satu pertanyaan tertentu.'),
            Textarea::make('content')->label('Isi / jawaban')->required()->rows(8)->maxLength(4000)
                ->helperText(Industry::t('knowledge_body_hint')),
            Toggle::make('active')->label('Dipakai AI')->default(true),
        ]);
    }

    /** The columns the general list and a product's tab share. */
    public static function columns(): array
    {
        return [
            TextColumn::make('title')->label('Judul')->searchable()->description(fn (AiKnowledgeItem $r) => str($r->content)->limit(90)),
            TextColumn::make('source')->label('Asal')->badge()->formatStateUsing(fn ($state) => match ($state) {
                'learned' => 'Dipelajari',
                'upload' => 'Dokumen',
                default => 'Manual',
            }),
        ];
    }

    /**
     * Only the general items (for every product) by default; a product's own items are managed on that product's
     * page and show here read-only when asked for.
     */
    public static function table(Table $table): Table
    {
        $item = Industry::t('catalog_item');

        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query, $livewire) => ($livewire->tableFilters['with_products']['isActive'] ?? false)
                ? $query->with('property')
                : $query->whereNull('property_id'))
            ->columns([
                ...self::columns(),
                TextColumn::make('property.name')->label('Berlaku untuk')->badge()->color('gray')->placeholder('Semua '.$item)->toggleable(),
                ToggleColumn::make('active')->label('Dipakai')->disabled(fn (AiKnowledgeItem $r) => $r->property_id !== null),
            ])->filters([
                Filter::make('with_products')->label('Tampilkan juga pengetahuan '.$item)->toggle()->query(fn (Builder $q) => $q),
            ])->recordActions([
                EditAction::make()->visible(fn (AiKnowledgeItem $r) => $r->property_id === null),
                DeleteAction::make()->visible(fn (AiKnowledgeItem $r) => $r->property_id === null),
                Action::make('openProduct')->label('Buka di '.$item)->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                    ->visible(fn (AiKnowledgeItem $r) => $r->property_id !== null)
                    ->url(fn (AiKnowledgeItem $r) => PropertyResource::getUrl('edit', ['record' => $r->property_id, 'relation' => EditProperty::KNOWLEDGE])),
            ])
            ->emptyStateHeading('Belum ada pengetahuan umum')
            ->emptyStateDescription('Tambahkan jawaban yang berlaku untuk semua '.$item.' (alamat, jam buka, cara bayar). Pengetahuan khusus satu '.$item.' diatur di halaman '.$item.' itu.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageAiKnowledge::route('/')];
    }
}
