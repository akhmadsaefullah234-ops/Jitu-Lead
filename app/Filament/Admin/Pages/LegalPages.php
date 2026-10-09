<?php

namespace App\Filament\Admin\Pages;

use App\Legal\LegalDocs;
use App\Models\LegalDocument;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** Edit the privacy policy and terms of service shown to the public. */
class LegalPages extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Halaman hukum';

    protected static ?string $title = 'Kebijakan privasi dan syarat layanan';

    protected static ?string $slug = 'legal-pages';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.admin.legal-pages';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $state = [];

        foreach (LegalDocs::SLUGS as $slug) {
            $doc = LegalDocs::get($slug);
            $row = LegalDocument::query()->where('slug', $slug)->first();
            $state[$slug] = [
                'title' => $row?->title ?: $doc['title'],
                'body' => $doc['custom'] ? $row->body : LegalDocs::defaultHtml($slug),
                'reviewed' => $doc['reviewed'],
            ];
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Tabs::make('docs')->tabs(array_map(fn (string $slug) => Tab::make(config("legal.documents.$slug.title"))->schema([
                Section::make()->schema([
                    TextInput::make("$slug.title")->label('Judul')->required()->maxLength(120),
                    RichEditor::make("$slug.body")->label('Isi')->required()
                        ->toolbarButtons([['h2', 'h3', 'bold', 'italic', 'link'], ['bulletList', 'orderedList']])
                        ->helperText('Penanda yang diganti otomatis: {perusahaan} {kontak} {alamat} {aplikasi} {backup_hari} {backup_luar_hari} {tanggal}.'),
                    Toggle::make("$slug.reviewed")->label('Sudah ditinjau ahli hukum')
                        ->helperText('Selama mati, halaman publik menampilkan pita "Rancangan, belum ditinjau ahli hukum".'),
                ]),
            ]), LegalDocs::SLUGS)),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label('Simpan')->action('save'),
            Action::make('reset')->label('Kembalikan ke teks bawaan')->color('gray')->requiresConfirmation()
                ->modalDescription('Teks yang Anda ubah dihapus dan halaman kembali memakai teks bawaan (config/legal.php).')
                ->action(function () {
                    LegalDocument::query()->delete();
                    $this->mount();
                    Notification::make()->title('Dikembalikan ke teks bawaan')->success()->send();
                }),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();

        foreach (LegalDocs::SLUGS as $slug) {
            $d = $state[$slug];
            $previous = LegalDocument::query()->where('slug', $slug)->first();
            $unchanged = LegalDocs::normalize($d['body']) === LegalDocs::normalize(LegalDocs::defaultHtml($slug))
                && $d['title'] === config("legal.documents.$slug.title");

            // Keep the built-in text as the source until the owner really changes it, so config updates still flow through.
            if ($unchanged && ! $d['reviewed'] && $previous === null) {
                continue;
            }

            LegalDocument::query()->updateOrCreate(['slug' => $slug], [
                'title' => $d['title'],
                'body' => $unchanged ? null : $d['body'],
                'reviewed_at' => $d['reviewed'] ? ($previous?->reviewed_at ?? now()) : null,
            ]);
        }

        Notification::make()->title('Tersimpan')->success()->send();
    }
}
