<?php

namespace App\Filament\Resources\Properties\RelationManagers;

use App\Filament\Resources\AiKnowledge\AiKnowledgeResource;
use App\Filament\Resources\AiKnowledge\Pages\ManageAiKnowledge;
use App\Models\AiKnowledgeItem;
use App\Support\Industry;
use Filament\Actions\BulkAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/** The AI knowledge that only applies to this product: the AI uses it in chats about this product only. */
class KnowledgeRelationManager extends RelationManager
{
    protected static string $relationship = 'knowledgeItems';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedSparkles;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Pengetahuan AI';
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $active = $ownerRecord->knowledgeItems()->where('active', true)->count();

        return $active > 0 ? (string) $active : null;
    }

    public static function getBadgeTooltip(Model $ownerRecord, string $pageClass): ?string
    {
        return 'Catatan yang dipakai AI';
    }

    public function form(Schema $schema): Schema
    {
        return AiKnowledgeResource::form($schema);
    }

    public function table(Table $table): Table
    {
        $item = Industry::t('catalog_item');

        return $table->recordTitleAttribute('title')->defaultSort('id', 'desc')
            ->heading('Pengetahuan AI khusus '.$item.' ini')
            ->description("AI memakai catatan ini hanya di chat tentang {$this->getOwnerRecord()->name}, ditambah pengetahuan umum untuk semua {$item}.")
            ->columns([
                ...AiKnowledgeResource::columns(),
                TextColumn::make('question')->label('Pertanyaan')->limit(60)->placeholder('-')->toggleable(),
                ToggleColumn::make('active')->label('Dipakai'),
            ])
            ->headerActions([
                ManageAiKnowledge::uploadAction(fn () => $this->getOwnerRecord()->getKey())
                    ->modalDescription('Brosur, daftar harga, atau FAQ khusus '.$this->getOwnerRecord()->name.' dalam PDF (yang teksnya bisa disalin), Word (.docx), TXT, Markdown, atau CSV. Hasilnya otomatis berlaku untuk '.$item.' ini saja.'),
                CreateAction::make()->label('Tulis catatan'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([
                BulkAction::make('deactivate')->label('Nonaktifkan')->icon(Heroicon::OutlinedEyeSlash)->color('gray')
                    ->action(fn (Collection $records) => $records->each(fn (AiKnowledgeItem $r) => $r->update(['active' => false])))
                    ->deselectRecordsAfterCompletion(),
                DeleteBulkAction::make(),
            ])
            ->emptyStateHeading('Belum ada pengetahuan khusus '.$item.' ini')
            ->emptyStateDescription('Tulis atau unggah harga, spesifikasi, promo, dan jawaban yang sering ditanyakan tentang '.$item.' ini.');
    }
}
