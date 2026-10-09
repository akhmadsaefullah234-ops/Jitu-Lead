<?php

namespace App\Filament\Resources\Stages;

use App\Enums\StageRequirement;
use App\Enums\StageType;
use App\Filament\Resources\Stages\Pages\ManageStages;
use App\Models\Stage;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StageResource extends Resource
{
    protected static ?string $model = Stage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $modelLabel = 'tahap';

    protected static ?string $pluralModelLabel = 'tahap pipeline';

    protected static ?string $navigationLabel = 'Tahap pipeline';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nama tahap')->required()->maxLength(60),
            Select::make('type')->label('Jenis')->options(StageType::class)->required()->default(StageType::Open->value)
                ->helperText('Menang dan Gugur menutup lead. Pipeline butuh minimal satu tahap untuk tiap jenis.'),
            Select::make('requirement')->label('Syarat masuk tahap')->options(StageRequirement::class)->placeholder('Tanpa syarat')
                ->helperText('Data yang wajib diisi sebelum lead boleh pindah ke tahap ini.'),
            TextInput::make('default_action')->label('Tindak lanjut bawaan')->maxLength(120),
            TextInput::make('default_due_hours')->label('Batas waktu tindak lanjut (jam)')->numeric()->minValue(1)->maxValue(8760),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('position')->reorderable('position')->columns([
            TextColumn::make('name')->label('Tahap')->searchable(),
            TextColumn::make('type')->label('Jenis')->badge(),
            TextColumn::make('requirement')->label('Syarat')->placeholder('-'),
            TextColumn::make('default_action')->label('Tindak lanjut bawaan')->limit(40)->placeholder('-'),
            TextColumn::make('default_due_hours')->label('Batas (jam)')->placeholder('-'),
        ])->recordActions([
            EditAction::make(),
            DeleteAction::make()->tooltip(fn (Stage $record) => $record->leads()->withTrashed()->exists() ? 'Masih ada lead di tahap ini' : null),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageStages::route('/')];
    }
}
