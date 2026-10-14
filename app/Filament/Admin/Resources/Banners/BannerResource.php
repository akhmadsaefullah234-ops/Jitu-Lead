<?php

namespace App\Filament\Admin\Resources\Banners;

use App\Filament\Admin\Resources\Banners\Pages\ManageBanners;
use App\Models\Banner;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BannerResource extends Resource
{
    protected static ?string $model = Banner::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $modelLabel = 'banner';

    protected static ?string $pluralModelLabel = 'Banner dasbor';

    protected static ?string $navigationLabel = 'Banner dasbor';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('Judul (untuk pembaca layar dan daftar ini)')->required()->maxLength(120),
            FileUpload::make('image_path')->label('Gambar banner')->image()->disk('public')->directory('banners')->visibility('public')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(2048)
                ->helperText('JPG, PNG, atau WebP, maksimal 2 MB. Ukuran terbaik 1500 x 500 piksel (3:1); taruh tulisan penting di tengah agar tidak terpotong di HP.')
                ->required(),
            TextInput::make('link_url')->label('Tautan tujuan (opsional)')->url()->rule('regex:#^https?://#i')->maxLength(500)
                ->placeholder('https://...')->helperText('Dibuka di tab baru saat banner diklik. Kosongkan bila banner hanya informasi.'),
            Toggle::make('is_active')->label('Tampil')->default(true),
            TextInput::make('sort_order')->label('Urutan (angka kecil tampil lebih dulu)')->numeric()->minValue(0)->maxValue(9999)->default(0)->required(),
            DateTimePicker::make('starts_at')->label('Mulai tampil (opsional)')->native(false),
            DateTimePicker::make('ends_at')->label('Berhenti tampil (opsional)')->native(false)->after('starts_at'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort_order')->columns([
            ImageColumn::make('image_path')->label('Banner')->disk('public')->width(150)->height(50),
            TextColumn::make('title')->label('Judul')->searchable(),
            TextColumn::make('link_url')->label('Tautan')->limit(40)->placeholder('-'),
            IconColumn::make('is_active')->label('Tampil')->boolean(),
            TextColumn::make('sort_order')->label('Urutan')->sortable(),
            TextColumn::make('starts_at')->label('Mulai')->dateTime('d M Y H:i')->placeholder('-'),
            TextColumn::make('ends_at')->label('Selesai')->dateTime('d M Y H:i')->placeholder('-'),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageBanners::route('/')];
    }
}
