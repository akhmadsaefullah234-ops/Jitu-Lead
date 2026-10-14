<?php

namespace App\Filament\Resources\MediaAssets;

use App\Filament\Resources\MediaAssets\Pages\ManageMediaAssets;
use App\Media\Library;
use App\Models\MediaAsset;
use App\Models\MediaFolder;
use App\Support\CurrentTenant;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Number;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class MediaAssetResource extends Resource
{
    protected static ?string $model = MediaAsset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $modelLabel = 'item media';

    protected static ?string $pluralModelLabel = 'pustaka media';

    protected static ?string $navigationLabel = 'Pustaka media';

    protected static string|\UnitEnum|null $navigationGroup = 'Pustaka media';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('folder_id')->label('Folder')->required()
                ->options(fn () => MediaFolder::query()->orderBy('position')->pluck('name', 'id'))
                ->searchable()->preload(),
            TextInput::make('title')->label('Judul')->required()->maxLength(120)
                ->placeholder('Contoh: Price list Cluster Mawar, Denah tipe 36, Lokasi kantor pemasaran'),
            Radio::make('kind')->label('Jenis')->options(['file' => 'File atau gambar', 'link' => 'Link (Google Maps, video, dll)'])
                ->default('file')->inline()->live()->required(),
            FileUpload::make('path')->label('File')->disk('local')->visibility('private')
                ->directory(fn () => 'media-library/'.app(CurrentTenant::class)->id())
                ->storeFileNamesIn('original_name')->maxSize((int) (Library::MAX_BYTES / 1024))
                ->rules([fn (): Closure => static::fileRule()])->downloadable(false)->openable(false)
                ->visible(fn ($get) => $get('kind') !== 'link')->required(fn ($get) => $get('kind') !== 'link')
                ->helperText('JPG, PNG, PDF, Word, Excel, PowerPoint, CSV, atau TXT. Maksimal 10 MB.'),
            TextInput::make('url')->label('Alamat link')->url()->maxLength(500)->rules(['regex:#^https?://#i'])
                ->placeholder('https://maps.app.goo.gl/...')
                ->visible(fn ($get) => $get('kind') === 'link')->required(fn ($get) => $get('kind') === 'link'),
            Textarea::make('description')->label('Kapan dipakai')->rows(3)->maxLength(500)
                ->helperText('Satu atau dua kalimat. AI membaca ini untuk memutuskan kapan mengirimnya, misalnya "Kirim bila klien menanyakan harga atau cicilan Cluster Mawar".'),
            Toggle::make('active')->label('Boleh dikirim')->default(true),
        ]);
    }

    /** Checks the real content of the upload, not just its name. */
    public static function fileRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value instanceof TemporaryUploadedFile && ! Library::accepts($value->getClientOriginalName(), self::detectedMime($value))) {
                $fail('Jenis file tidak didukung. Gunakan JPG, PNG, PDF, Word, Excel, PowerPoint, CSV, atau TXT.');
            }
        };
    }

    /** The type found by reading the file itself, not the one its name or the browser claims. */
    public static function detectedMime(TemporaryUploadedFile $file): ?string
    {
        $mime = @mime_content_type($file->getRealPath());

        return $mime === false ? null : $mime;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('title')->label('Judul')->searchable()->description(fn (MediaAsset $r) => $r->isLink() ? $r->url : $r->original_name),
            TextColumn::make('folder.name')->label('Folder')->badge(),
            TextColumn::make('size_bytes')->label('Ukuran')->formatStateUsing(fn ($state, MediaAsset $r) => $r->isLink() ? 'Link' : Number::fileSize((int) $state)),
            ToggleColumn::make('active')->label('Boleh dikirim'),
        ])->filters([
            SelectFilter::make('folder_id')->label('Folder')->options(fn () => MediaFolder::query()->orderBy('position')->pluck('name', 'id')),
        ])->recordActions([EditAction::make()->mutateDataUsing(fn (array $data) => ManageMediaAssets::prepare($data)), DeleteAction::make()])
            ->emptyStateHeading('Pustaka masih kosong')
            ->emptyStateDescription('Simpan price list, foto rumah, denah, dan link Google Maps di sini agar bisa dikirim ke klien dengan satu klik.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageMediaAssets::route('/')];
    }
}
