<?php

namespace App\Filament\Resources\Team;

use App\Billing\Deny;
use App\Billing\PlanLimits;
use App\Enums\Role;
use App\Filament\Resources\Team\Pages\ManageTeam;
use App\Models\Membership;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TeamResource extends Resource
{
    protected static ?string $model = Membership::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'anggota tim';

    protected static ?string $pluralModelLabel = 'tim';

    protected static ?string $navigationLabel = 'Tim';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 13;

    public static function getEloquentQuery(): Builder
    {
        return Membership::query()->where('tenant_id', app(CurrentTenant::class)->id())->with('user');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('role')->label('Peran')->options(Role::class)->required()
                ->disabled(fn (?Membership $record) => $record?->isLastActiveAdmin())
                ->helperText('Admin mengatur semuanya, Team leader melihat semua lead, Agen hanya lead miliknya. Agensi harus punya minimal satu admin aktif.'),
            Select::make('status')->label('Status')->options(['active' => 'Aktif', 'inactive' => 'Nonaktif'])->required()
                ->disabled(fn (?Membership $record) => $record?->isLastActiveAdmin())
                ->helperText('Anggota nonaktif tidak bisa masuk dan tidak dapat lead baru.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('user.name')->label('Nama')->searchable(),
            TextColumn::make('user.email')->label('Email')->searchable(),
            TextColumn::make('role')->label('Peran')->badge(),
            TextColumn::make('status')->label('Status')->badge()
                ->formatStateUsing(fn (string $state) => $state === 'active' ? 'Aktif' : 'Nonaktif')
                ->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
        ])->recordActions([
            EditAction::make()->before(function (EditAction $action, array $data, Membership $record) {
                // Turning someone back on takes a seat, so it is held to the same limit as adding.
                if (($data['status'] ?? null) === 'active' && $record->status !== 'active' && ($denied = PlanLimits::current()?->denyAdding('users'))) {
                    Deny::notify($denied);
                    $action->halt();
                }
            }),
            DeleteAction::make()->label('Keluarkan')->modalHeading('Keluarkan dari tim?')
                ->modalDescription('Lead miliknya tidak ikut terhapus; atur ulang pemiliknya dari daftar lead.'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTeam::route('/')];
    }
}
