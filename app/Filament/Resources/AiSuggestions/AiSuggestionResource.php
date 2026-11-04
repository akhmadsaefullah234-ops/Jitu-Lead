<?php

namespace App\Filament\Resources\AiSuggestions;

use App\Filament\Resources\AiSuggestions\Pages\ManageAiSuggestions;
use App\Models\AiKnowledgeItem;
use App\Models\AiSuggestion;
use App\Models\Lead;
use App\Models\Property;
use App\Support\Industry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AiSuggestionResource extends Resource
{
    protected static ?string $model = AiSuggestion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static ?string $modelLabel = 'usulan Q&A';

    protected static ?string $pluralModelLabel = 'usulan Q&A';

    protected static ?string $navigationLabel = 'Usulan Q&A';

    protected static string|\UnitEnum|null $navigationGroup = 'AI Asisten';

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        $pending = AiSuggestion::query()->where('status', 'pending')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('status', 'pending');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('question')->label('Pertanyaan klien')->wrap()->limit(120),
            TextColumn::make('answer')->label('Jawaban agen')->wrap()->limit(160),
        ])->recordActions([
            Action::make('approve')->label('Setujui')->icon(Heroicon::OutlinedCheck)->color('success')
                ->modalHeading('Jadikan pengetahuan AI')
                // Saved to the product the conversation was about, unless the owner picks another or none.
                ->fillForm(fn (AiSuggestion $record) => [
                    ...$record->only(['question', 'answer']),
                    'property_id' => Lead::query()->whereKey($record->lead_id)->value('property_id'),
                ])
                ->schema([
                    Textarea::make('question')->label('Pertanyaan')->required()->rows(2)->maxLength(500),
                    Textarea::make('answer')->label('Jawaban')->required()->rows(5)->maxLength(2000),
                    Select::make('property_id')->label('Simpan ke')->searchable()
                        ->options(fn () => Property::query()->orderBy('name')->pluck('name', 'id'))
                        ->placeholder('Pengetahuan umum (semua '.Industry::t('catalog_item').')')
                        ->helperText('Bawaan: '.Industry::t('catalog_item').' yang dibahas di chat itu. Kosongkan bila jawabannya berlaku untuk semua '.Industry::t('catalog_item').'.'),
                ])
                ->action(function (AiSuggestion $record, array $data) {
                    AiKnowledgeItem::query()->create([
                        'title' => str($data['question'])->limit(80)->toString(),
                        'question' => $data['question'],
                        'content' => $data['answer'],
                        'source' => 'learned',
                        'active' => true,
                        'property_id' => filled($data['property_id'] ?? null) ? Property::query()->whereKey($data['property_id'])->value('id') : null,
                    ]);
                    $record->update(['status' => 'approved']);
                    Notification::make()->title('Masuk ke pengetahuan AI')->success()->send();
                }),
            Action::make('reject')->label('Tolak')->icon(Heroicon::OutlinedXMark)->color('gray')->requiresConfirmation()
                ->action(fn (AiSuggestion $record) => $record->update(['status' => 'rejected'])),
        ])->emptyStateHeading('Tidak ada usulan')
            ->emptyStateDescription('Saat agen menjawab pertanyaan klien, jawabannya muncul di sini untuk Anda setujui.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageAiSuggestions::route('/')];
    }
}
