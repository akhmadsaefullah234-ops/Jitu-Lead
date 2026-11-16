<?php

namespace App\Filament\Resources\AiKnowledge\Pages;

use App\Actions\ImportKnowledgeDocument;
use App\Ai\AiSettings;
use App\Ai\AnthropicClient;
use App\Ai\DocumentException;
use App\Ai\DocumentReader;
use App\Filament\Resources\AiKnowledge\AiKnowledgeResource;
use App\Models\MediaAsset;
use App\Privacy\KnowledgeScan;
use App\Support\CurrentTenant;
use App\Support\Industry;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ManageAiKnowledge extends ManageRecords
{
    protected static string $resource = AiKnowledgeResource::class;

    public const TEMPLATES = [
        'template-pengetahuan-agensi.txt' => 'Template pengetahuan agensi (TXT)',
        'template-tanya-jawab.csv' => 'Template tanya-jawab (CSV)',
    ];

    public function getTitle(): string
    {
        return 'Pengetahuan umum (semua '.Industry::t('catalog_item').')';
    }

    public function getSubheading(): string|Htmlable|null
    {
        $item = Industry::t('catalog_item');
        $text = app(AnthropicClient::class)->configured()
            ? "Yang berlaku untuk semua {$item}: alamat, jam buka, cara bayar, aturan umum. Pengetahuan khusus satu {$item} (harga, promo, cara pakai) diatur di halaman {$item} itu, tab Pengetahuan AI. AI hanya menjawab dari catatan ini dan catatan {$item} yang sedang dibahas. Memilih siapa yang membalas chat ada di menu Mode AI."
            : 'Layanan AI belum diaktifkan di server ini, hubungi penyedia aplikasi. Catatan di sini tetap tersimpan.';
        $warning = self::personalDataWarning();

        return $warning === null ? $text : new HtmlString($warning.'<div>'.e($text).'</div>');
    }

    /**
     * Red at the top while any entry the AI uses holds a buyer's personal data: which, what it holds, and where it
     * is (a product's entries live on that product's page). Null when there is none.
     */
    public static function personalDataWarning(): ?string
    {
        $flagged = KnowledgeScan::flagged();

        if ($flagged->isEmpty()) {
            return null;
        }

        $rows = $flagged->take(10)->map(fn (array $row) => '<li>#'.$row['item']->getKey().' '.e(str($row['item']->title)->limit(60))
            .($row['item']->property ? ' <em>('.e($row['item']->property->name).')</em>' : '')
            .': '.e(implode('; ', array_map(fn ($f) => (string) str($f)->limit(60), array_slice($row['findings'], 0, 3)))).'</li>')->implode('');

        return '<div style="color:var(--danger-700);padding:.75rem 1rem;margin-bottom:.75rem;border:1px solid var(--danger-300);border-radius:.5rem;background:var(--danger-50);font-size:.875rem">'
            .'<strong>'.$flagged->count().' catatan pengetahuan memuat data pribadi pembeli.</strong> AI bisa salah menyapa atau menyebut alamat pembeli lain. '
            .'Nonaktifkan atau sunting catatan ini (tombol "Nonaktifkan yang memuat data pribadi", atau filter "Hanya yang memuat data pribadi").'
            .'<ul style="margin:.4rem 0 0 1rem;list-style:disc">'.$rows.'</ul>'
            .($flagged->count() > 10 ? '<div>dan '.($flagged->count() - 10).' lainnya.</div>' : '')
            .'</div>';
    }

    protected function getHeaderActions(): array
    {
        return [
            self::uploadAction(),
            ActionGroup::make(collect(self::TEMPLATES)->map(fn (string $label, string $file) => Action::make('template-'.pathinfo($file, PATHINFO_FILENAME))
                ->label($label)->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn () => response()->download(resource_path("ai-templates/$file"), $file)))->values()->all())
                ->label('Unduh template')->icon(Heroicon::OutlinedDocumentText)->color('gray')->button(),
            $this->deactivatePersonalAction(),
            $this->settingsAction(),
            CreateAction::make()->label('Tulis catatan'),
        ];
    }

    /** @param  Closure(): ?int|null  $propertyId  the product the new items belong to; none = general */
    public static function uploadAction(?Closure $propertyId = null): Action
    {
        return Action::make('upload')->label('Unggah dokumen')->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('Unggah dokumen pengetahuan')
            ->modalDescription('Brosur, daftar harga, atau FAQ dalam PDF (yang teksnya bisa disalin), Word (.docx), TXT, Markdown, atau CSV. Dokumen panjang dipecah otomatis. Belum punya? Unduh template di sebelahnya.')
            ->modalSubmitActionLabel('Baca dan simpan')
            ->schema([
                FileUpload::make('file')->label('File')->required()->storeFiles(false)->maxSize(DocumentReader::MAX_BYTES / 1024)
                    ->acceptedFileTypes([
                        'application/pdf', 'text/plain', 'text/markdown', 'text/csv', 'application/csv', 'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    ])
                    ->helperText('Maksimal 5 MB. Jangan unggah dokumen berisi data pribadi klien.'),
                TextInput::make('title')->label('Judul (opsional)')->maxLength(100)->helperText('Kosongkan untuk memakai nama file.'),
            ])
            ->action(function (array $data) use ($propertyId) {
                $file = $data['file'];

                if (! $file instanceof TemporaryUploadedFile) {
                    Notification::make()->title('File tidak terbaca, coba unggah lagi')->danger()->send();

                    return;
                }

                try {
                    $result = app(ImportKnowledgeDocument::class)($file->getRealPath(), $file->getClientOriginalName(), $data['title'] ?? null, $propertyId ? $propertyId() : null);
                } catch (DocumentException $e) {
                    Notification::make()->title('Dokumen belum bisa dipakai')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title($result['items'].' catatan ditambahkan')
                    ->body($result['truncated'] ? 'Dokumen sangat panjang, hanya bagian awalnya yang dipakai (sekitar 60.000 karakter). Pecah dokumen jika perlu.' : 'Periksa hasilnya di daftar, lalu nonaktifkan bagian yang tidak perlu.')
                    ->success()->send();
            });
    }

    /** Every entry the AI still uses that holds a buyer's personal data is switched off at once (general and per product). */
    private function deactivatePersonalAction(): Action
    {
        return Action::make('deactivatePersonal')->label('Nonaktifkan yang memuat data pribadi')->icon(Heroicon::OutlinedShieldExclamation)->color('danger')
            ->visible(fn () => KnowledgeScan::ids() !== [])
            ->requiresConfirmation()
            ->modalHeading('Nonaktifkan catatan berisi data pribadi')
            ->modalDescription(fn () => count(KnowledgeScan::ids()).' catatan tidak lagi dipakai AI. Catatannya tetap tersimpan; Anda bisa menyunting lalu mengaktifkannya lagi.')
            ->action(function () {
                $count = 0;

                foreach (KnowledgeScan::flagged() as ['item' => $item]) {
                    $item->update(['active' => false]);
                    $count++;
                }

                AiKnowledgeResource::forgetFlagged();
                Notification::make()->title($count.' catatan dinonaktifkan')->success()->send();
            });
    }

    /** Defaults are already filled in for every agency; this is only for changing them. */
    private function settingsAction(): Action
    {
        return Action::make('settings')->label('Arahan AI (lanjutan)')->icon(Heroicon::OutlinedCog6Tooth)->color('gray')
            ->modalHeading('Arahan AI (lanjutan)')
            ->modalDescription('Sudah terisi bawaan yang siap pakai, Anda tidak perlu mengubahnya. Aturan dasar tetap berlaku: AI hanya menjawab dari pengetahuan dan menyerahkan chat ke agen bila tidak yakin.')
            ->fillForm(fn () => app(CurrentTenant::class)->get()->only(['ai_instructions', 'ai_handoff_keywords']) + AiSettings::of(app(CurrentTenant::class)->get())->form())
            ->schema([
                Textarea::make('ai_instructions')->label('Gaya dan aturan khusus')->rows(8)->maxLength(2000),
                Radio::make('format')->label('Bentuk balasan')->options(AiSettings::FORMATS)->live()
                    ->helperText('Bawaan: singkat tanpa format. Format WhatsApp cocok untuk jawaban berbentuk blok, misalnya harga, legalitas, atau lokasi.'),
                TextInput::make('max_lines')->label('Paling banyak baris per balasan')->numeric()->minValue(1)->maxValue(AiSettings::MAX_LINES)
                    ->visible(fn (Get $get) => $get('format') === 'whatsapp'),
                Toggle::make('product_list')->label('Tanyakan tipe lewat daftar')
                    ->helperText('Hidup (bawaan): bila tipe atau produk yang ditanyakan klien belum jelas, AI menanyakannya dengan menyebut pilihannya dan belum menyebut harga. Matikan bila sapaan pertama harus mengikuti arahan Anda: AI tidak menyodorkan daftar dan boleh menjawab harga umum dari pengetahuan.'),
                Textarea::make('ai_handoff_keywords')->label('Kata yang membuat AI menyerahkan chat ke agen')->rows(4)->maxLength(1000)
                    ->helperText('Pisahkan dengan koma. Jika Anda menghapus sebuah kata, AI akan ikut menjawab pesan yang memuatnya.'),
                Section::make('Pemeriksa sebelum kirim otomatis')->compact()
                    ->visible(fn () => app(CurrentTenant::class)->get()?->industry()->key === 'properti')
                    ->schema([
                        Toggle::make('guard')->label('Tahan balasan yang memuat angka atau frasa yang tidak ada di pengetahuan')
                            ->helperText('Harga, persentase, tahun, dan luas harus ada di pengetahuan, data lead, atau chat. Bila tidak, balasan tidak dikirim dan menunggu agen di Inbox, dengan alasannya di aktivitas lead.'),
                        Textarea::make('forbidden_phrases')->label('Frasa terlarang')->rows(2)
                            ->placeholder(implode(', ', AiSettings::SUGGESTED_PHRASES))
                            ->helperText('Pisahkan dengan koma. Frasa yang langsung disangkal ("belum siap huni", "SHM belum tersedia") tidak dihitung.'),
                    ]),
                Section::make('Jeda dan jam malam (hanya mode otomatis)')->compact()->columns(2)
                    ->schema([
                        TextInput::make('delay_min')->label('Jeda paling cepat (detik)')->numeric()->minValue(0)->maxValue(AiSettings::MAX_DELAY),
                        TextInput::make('delay_max')->label('Jeda paling lama (detik)')->numeric()->minValue(0)->maxValue(AiSettings::MAX_DELAY)
                            ->helperText('0 = langsung. Contoh 5 dan 25: balasan dikirim acak 5 sampai 25 detik setelah pesan masuk.'),
                        Toggle::make('typing')->label('Tampilkan "mengetik..." selama jeda (nomor scan QR)')->columnSpanFull(),
                        TimePicker::make('quiet_from')->label('Jam malam mulai')->seconds(false),
                        TimePicker::make('quiet_until')->label('Jam malam selesai')->seconds(false)
                            ->helperText('Kosongkan keduanya bila AI boleh membalas kapan saja. Zona waktu agensi.'),
                        Radio::make('quiet_mode')->label('Di jam malam')->options(AiSettings::QUIET_MODES)->columnSpanFull(),
                        Textarea::make('quiet_message')->label('Sambutan jam malam')->rows(2)->maxLength(500)->columnSpanFull(),
                    ]),
                Section::make('Lampiran sambutan (hanya mode otomatis)')->compact()
                    ->schema([
                        Select::make('welcome_assets')->label('Lampirkan di balasan otomatis pertama ke tiap lead')->multiple()->maxItems(AiSettings::MAX_WELCOME_ASSETS)
                            ->options(fn () => MediaAsset::query()->where('active', true)->orderBy('title')->pluck('title', 'id')->all())
                            ->helperText('Misalnya brosur dan daftar harga dari Pustaka media. Dikirim sekali per lead, sesudah balasan pertama AI. Kosongkan bila tidak perlu.'),
                    ]),
                Section::make('Saat chat ditangani agen')->compact()->columns(2)
                    ->schema([
                        TextInput::make('agent_pause')->label('AI diam setelah agen membalas (menit)')->numeric()->minValue(1)->maxValue(AiSettings::MAX_PAUSE_MINUTES)
                            ->helperText('Berlaku untuk balasan dari Inbox dan dari WhatsApp di HP. Bawaan 5 menit.'),
                        TextInput::make('handoff_pause')->label('AI diam setelah menyerahkan chat (menit)')->numeric()->minValue(0)->maxValue(AiSettings::MAX_PAUSE_MINUTES)
                            ->helperText('0 (bawaan): pertanyaan berikutnya yang ada di pengetahuan tetap dijawab AI. Isi misalnya 60 agar chat yang diserahkan dijawab agen saja.'),
                    ]),
            ])
            ->extraModalFooterActions([
                Action::make('propertyHandoff')->label('Tambah kata khusus properti')->color('gray')->cancelParentActions()
                    ->visible(fn () => app(CurrentTenant::class)->get()?->industry()->key === 'properti')
                    ->modalDescription('Ditambahkan ke daftar serah-terima: '.implode(', ', AiSettings::PROPERTY_HANDOFF).'. Kata yang sudah ada tidak digandakan.')
                    ->requiresConfirmation()
                    ->action(function () {
                        $tenant = app(CurrentTenant::class)->get();
                        $words = array_map('trim', preg_split('/[,\n]+/', (string) ($tenant->ai_handoff_keywords ?: implode(', ', $tenant->industry()->config('handoff_keywords')))) ?: []);
                        $missing = array_filter(AiSettings::PROPERTY_HANDOFF, fn ($w) => ! in_array($w, array_map('mb_strtolower', $words), true));
                        $tenant->update(['ai_handoff_keywords' => implode(', ', array_values(array_filter([...$words, ...$missing])))]);
                        Notification::make()->title(count($missing).' kata ditambahkan')->success()->send();
                    }),
                Action::make('reset')->label('Kembalikan ke bawaan')->color('gray')->cancelParentActions()
                    ->requiresConfirmation()
                    ->action(function () {
                        $tenant = app(CurrentTenant::class)->get();
                        $tenant->update([
                            'ai_instructions' => $tenant->industry()->config('ai_instructions'),
                            'ai_handoff_keywords' => implode(', ', $tenant->industry()->config('handoff_keywords')),
                        ]);
                        Notification::make()->title('Dikembalikan ke bawaan')->success()->send();
                    }),
            ])
            ->action(function (array $data) {
                app(CurrentTenant::class)->get()->update([
                    'ai_instructions' => $data['ai_instructions'] ?: null,
                    'ai_handoff_keywords' => $data['ai_handoff_keywords'] ?: null,
                    'ai_settings' => AiSettings::fromForm($data),
                ]);
                Notification::make()->title('Arahan disimpan')->success()->send();
            });
    }
}
