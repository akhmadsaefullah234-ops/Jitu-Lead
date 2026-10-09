<?php

namespace App\Filament\Resources\AiKnowledge\Pages;

use App\Actions\ImportKnowledgeDocument;
use App\Ai\AnthropicClient;
use App\Ai\Defaults;
use App\Ai\DocumentException;
use App\Ai\DocumentReader;
use App\Filament\Resources\AiKnowledge\AiKnowledgeResource;
use App\Support\CurrentTenant;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ManageAiKnowledge extends ManageRecords
{
    protected static string $resource = AiKnowledgeResource::class;

    public const TEMPLATES = [
        'template-pengetahuan-agensi.txt' => 'Template pengetahuan agensi (TXT)',
        'template-tanya-jawab.csv' => 'Template tanya-jawab (CSV)',
    ];

    public function getSubheading(): ?string
    {
        return app(AnthropicClient::class)->configured()
            ? 'Semua yang AI ketahui tentang agensi Anda ada di sini. AI hanya menjawab dari catatan ini. Mengatur AI aktif atau tidak per nomor ada di menu Mode AI.'
            : 'AI belum aktif: kunci API (ANTHROPIC_API_KEY) belum diisi di server. Catatan di sini tetap tersimpan.';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->uploadAction(),
            ActionGroup::make(collect(self::TEMPLATES)->map(fn (string $label, string $file) => Action::make('template-'.pathinfo($file, PATHINFO_FILENAME))
                ->label($label)->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn () => response()->download(resource_path("ai-templates/$file"), $file)))->values()->all())
                ->label('Unduh template')->icon(Heroicon::OutlinedDocumentText)->color('gray')->button(),
            $this->settingsAction(),
            CreateAction::make()->label('Tulis catatan'),
        ];
    }

    private function uploadAction(): Action
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
            ->action(function (array $data) {
                $file = $data['file'];

                if (! $file instanceof TemporaryUploadedFile) {
                    Notification::make()->title('File tidak terbaca, coba unggah lagi')->danger()->send();

                    return;
                }

                try {
                    $result = app(ImportKnowledgeDocument::class)($file->getRealPath(), $file->getClientOriginalName(), $data['title'] ?? null);
                } catch (DocumentException $e) {
                    Notification::make()->title('Dokumen belum bisa dipakai')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title($result['items'].' catatan ditambahkan')
                    ->body($result['truncated'] ? 'Dokumen sangat panjang, hanya bagian awalnya yang dipakai (sekitar 60.000 karakter). Pecah dokumen jika perlu.' : 'Periksa hasilnya di daftar, lalu nonaktifkan bagian yang tidak perlu.')
                    ->success()->send();
            });
    }

    /** Defaults are already filled in for every agency; this is only for changing them. */
    private function settingsAction(): Action
    {
        return Action::make('settings')->label('Arahan AI (lanjutan)')->icon(Heroicon::OutlinedCog6Tooth)->color('gray')
            ->modalHeading('Arahan AI (lanjutan)')
            ->modalDescription('Sudah terisi bawaan yang siap pakai, Anda tidak perlu mengubahnya. Aturan dasar tetap berlaku: AI hanya menjawab dari pengetahuan dan menyerahkan chat ke agen bila tidak yakin.')
            ->fillForm(fn () => app(CurrentTenant::class)->get()->only(['ai_instructions', 'ai_handoff_keywords']))
            ->schema([
                Textarea::make('ai_instructions')->label('Gaya dan aturan khusus')->rows(8)->maxLength(2000),
                Textarea::make('ai_handoff_keywords')->label('Kata yang membuat AI menyerahkan chat ke agen')->rows(4)->maxLength(1000)
                    ->helperText('Pisahkan dengan koma. Jika Anda menghapus sebuah kata, AI akan ikut menjawab pesan yang memuatnya.'),
            ])
            ->extraModalFooterActions([
                Action::make('reset')->label('Kembalikan ke bawaan')->color('gray')->cancelParentActions()
                    ->requiresConfirmation()
                    ->action(function () {
                        app(CurrentTenant::class)->get()->update(['ai_instructions' => Defaults::INSTRUCTIONS, 'ai_handoff_keywords' => Defaults::keywordText()]);
                        Notification::make()->title('Dikembalikan ke bawaan')->success()->send();
                    }),
            ])
            ->action(function (array $data) {
                app(CurrentTenant::class)->get()->update([
                    'ai_instructions' => $data['ai_instructions'] ?: null,
                    'ai_handoff_keywords' => $data['ai_handoff_keywords'] ?: null,
                ]);
                Notification::make()->title('Arahan disimpan')->success()->send();
            });
    }
}
