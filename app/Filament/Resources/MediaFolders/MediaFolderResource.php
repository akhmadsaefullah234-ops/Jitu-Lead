<?php

namespace App\Filament\Resources\MediaFolders;

use App\Filament\Resources\MediaFolders\Pages\ManageMediaFolders;
use App\Models\MediaFolder;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class MediaFolderResource extends Resource
{
    protected static ?string $model = MediaFolder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static ?string $modelLabel = 'folder';

    protected static ?string $pluralModelLabel = 'folder media';

    protected static ?string $navigationLabel = 'Folder media';

    protected static string|\UnitEnum|null $navigationGroup = 'Pustaka media';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nama folder')->required()->maxLength(60)
                ->placeholder('Contoh: Price list, Foto rumah, Denah')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('tenant_id', app(CurrentTenant::class)->id())),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('position')->columns([
            TextColumn::make('name')->label('Folder')->searchable(),
            TextColumn::make('assets_count')->label('Isi')->counts('assets')->suffix(' item'),
        ])->recordActions([
            EditAction::make(),
            DeleteAction::make()->hidden(fn (MediaFolder $r) => $r->assets()->exists()),
        ])->emptyStateHeading('Belum ada folder');
    }

    public static function getPages(): array
    {
        return ['index' => ManageMediaFolders::route('/')];
    }
}
