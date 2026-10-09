<?php

namespace App\Filament\Pages;

use App\Actions\ImportLeadsFromCsv;
use App\Models\Lead;
use App\Models\User;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class ImportLeads extends Page
{
    protected string $view = 'filament.pages.import-leads';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $navigationLabel = 'Impor lead';

    protected static ?string $title = 'Impor lead';

    protected static ?int $navigationSort = 8;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('create', Lead::class) ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')->label('Unggah file CSV')->icon(Heroicon::OutlinedArrowUpTray)
                ->modalSubmitActionLabel('Impor')
                ->schema([
                    FileUpload::make('file')->label('File CSV')->required()->disk('local')->directory('imports')->visibility('private')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                        ->maxSize(2048)->storeFiles(true),
                    TextInput::make('source')->label('Sumber lead')->default('Impor')->required()->maxLength(60)
                        ->helperText('Contoh: Pameran Mei, Data lama.'),
                    Select::make('owner_id')->label('Berikan kepada')->placeholder('Bagi rata ke semua agen')
                        ->options(fn () => $this->owners())->visible(fn () => $this->canPickOwner()),
                ])
                ->action(function (array $data) {
                    $path = Storage::disk('local')->path($data['file']);

                    try {
                        $result = app(ImportLeadsFromCsv::class)($path, $data['source'], $this->canPickOwner() ? ($data['owner_id'] ?? null) : auth()->id());
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->title('Impor gagal')->body($e->getMessage())->danger()->send();

                        return;
                    } finally {
                        Storage::disk('local')->delete($data['file']);
                    }

                    $body = "{$result['created']} lead baru, {$result['duplicates']} sudah ada (dilewati).";
                    if ($result['invalid'] !== []) {
                        $body .= ' '.count($result['invalid']).' baris ditolak: '.implode('; ', array_slice($result['invalid'], 0, 5));
                    }

                    Notification::make()->title('Impor selesai')->body($body)->success()->persistent()->send();
                }),
        ];
    }

    private function canPickOwner(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user())?->seesAllLeads() ?? false;
    }

    /** @return array<int, string> */
    private function owners(): array
    {
        return app(CurrentTenant::class)->get()->activeUsers()->orderBy('name')->get()
            ->mapWithKeys(fn (User $u) => [$u->getKey() => $u->name])->all();
    }
}
