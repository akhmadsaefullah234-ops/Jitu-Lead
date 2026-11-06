<?php

namespace App\Filament\Resources\AiSuggestions;

use App\Filament\Resources\AiSuggestions\Pages\ManageAiSuggestions;
use App\Models\AiKnowledgeItem;
use App\Models\AiSuggestion;
use App\Models\Lead;
use App\Models\Property;
use App\Privacy\PersonalData;
use App\Support\Industry;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

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
                ->modalDescription('Pratinjau ini sudah dibersihkan dari data pribadi pembeli (nama, nomor HP, alamat, kota, e-mail, rekening diganti [nama], [alamat], ...). Periksa lagi sebelum menyimpan: AI memakainya sebagai contoh untuk semua pembeli.')
                // Saved to the product the conversation was about, unless the owner picks another or none.
                ->fillForm(function (AiSuggestion $record) {
                    $clean = PersonalData::scrubPair((string) $record->question, (string) $record->answer, PersonalData::keep());

                    return [
                        'question' => $clean['question'],
                        'answer' => $clean['answer'],
                        'property_id' => Lead::query()->whereKey($record->lead_id)->value('property_id'),
                    ];
                })
                ->schema([
                    Text::make(fn (AiSuggestion $record) => self::originalBox($record))->columnSpanFull()->key('original'),
                    Textarea::make('question')->label('Pertanyaan')->required()->rows(2)->maxLength(500)->live(onBlur: true)
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) {
                            if (PersonalData::hasPersonalPlaceholder((string) $value)) {
                                $fail('Pertanyaan ini pesan order atau data satu pembeli. Tulis ulang sebagai pertanyaan umum (tanpa [nama], [alamat], [nomor HP]) atau tolak usulannya.');
                            } elseif (($found = PersonalData::blocking(PersonalData::findings((string) $value, PersonalData::keep()))) !== []) {
                                $fail('Masih ada data pribadi: '.implode('; ', $found).'.');
                            }
                        }),
                    Textarea::make('answer')->label('Jawaban')->required()->rows(5)->maxLength(2000)->live(onBlur: true)
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) {
                            if (($found = PersonalData::blocking(PersonalData::findings((string) $value, PersonalData::keep()))) !== []) {
                                $fail('Masih ada data pribadi: '.implode('; ', $found).'.');
                            }
                        }),
                    Text::make(fn (Get $get) => self::warningBox((string) $get('question'), (string) $get('answer')))->columnSpanFull()->key('warning'),
                    Select::make('property_id')->label('Simpan ke')->searchable()
                        ->options(fn () => Property::query()->orderBy('name')->pluck('name', 'id'))
                        ->placeholder('Pengetahuan umum (semua '.Industry::t('catalog_item').')')
                        ->helperText('Bawaan: '.Industry::t('catalog_item').' yang dibahas di chat itu. Kosongkan bila jawabannya berlaku untuk semua '.Industry::t('catalog_item').'.'),
                ])
                ->action(function (AiSuggestion $record, array $data) {
                    // Cleaned once more as it is saved, in case something was typed back in.
                    $clean = PersonalData::scrubPair((string) $data['question'], (string) $data['answer'], PersonalData::keep());

                    AiKnowledgeItem::query()->create([
                        'title' => str($clean['question'])->limit(80)->toString(),
                        'question' => $clean['question'],
                        'content' => $clean['answer'],
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

    /** What the cleaning took out of the chat, so the admin sees why the preview differs from it. */
    private static function originalBox(AiSuggestion $record): ?HtmlString
    {
        $found = PersonalData::scrubPair((string) $record->question, (string) $record->answer, PersonalData::keep())['findings'];

        if ($found === []) {
            return null;
        }

        $order = PersonalData::isPersonalMessage((string) $record->question, PersonalData::keep());

        return new HtmlString('<div style="color:var(--warning-700);padding:.5rem .75rem;border:1px solid var(--warning-300);border-radius:.5rem;background:var(--warning-50);font-size:.875rem">'
            .'<strong>Data pribadi pembeli sudah dihapus dari pratinjau:</strong> '.e(implode('; ', array_map(fn ($f) => strtok($f, ':'), $found)))
            .($order ? '<br>Pertanyaannya pesan order atau alamat satu pembeli. Sebaiknya <strong>tolak</strong> usulan ini, atau tulis ulang menjadi pertanyaan umum.' : '')
            .'</div>');
    }

    /** Red while the text about to be saved still holds a buyer's data. */
    private static function warningBox(string $question, string $answer): ?HtmlString
    {
        $keep = PersonalData::keep();
        $all = array_values(array_unique([...PersonalData::findings($question, $keep), ...PersonalData::findings($answer, $keep)]));
        $found = PersonalData::blocking($all);
        $cities = array_diff($all, $found);
        $order = PersonalData::hasPersonalPlaceholder($question);

        if ($all === [] && ! $order) {
            return null;
        }

        if ($found === [] && ! $order) {
            return new HtmlString('<div style="color:var(--warning-700);padding:.5rem .75rem;border:1px solid var(--warning-300);border-radius:.5rem;background:var(--warning-50);font-size:.875rem">'
                .'<strong>Nama kota akan diganti [kota] saat disimpan:</strong> '.e(implode('; ', $cities)).'</div>');
        }

        return new HtmlString('<div style="color:var(--danger-600);padding:.5rem .75rem;border:1px solid var(--danger-300);border-radius:.5rem;background:var(--danger-50);font-size:.875rem">'
            .'<strong>Masih terdeteksi data pribadi, belum bisa disimpan.</strong>'
            .($found !== [] ? '<br>'.implode('<br>', array_map(fn ($f) => '• '.e($f), $found)) : '')
            .($order ? '<br>• Pertanyaan masih berupa pesan order/alamat satu pembeli; simpan hanya pertanyaan umum.' : '')
            .'</div>');
    }

    public static function getPages(): array
    {
        return ['index' => ManageAiSuggestions::route('/')];
    }
}
