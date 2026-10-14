<?php

namespace App\Filament\Resources\Team;

use App\Actions\AssignLead;
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
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
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
            Toggle::make('takes_leads')->label('Ikut dibagi lead baru')
                ->default(fn (?Membership $record) => $record ? static::takesLeads($record) : true)
                ->afterStateHydrated(fn (Toggle $component, ?Membership $record) => $record ? $component->state(static::takesLeads($record)) : null)
                ->helperText('Agen otomatis ikut. Aktifkan untuk Admin atau Team leader yang juga ingin menerima dan menangani lead sendiri. Admin tetap bisa melihat semua lead dan chat.')
                ->live(),
            TextInput::make('lead_share')->label('Porsi lead (%)')->numeric()->minValue(0)->maxValue(100)->suffix('%')
                ->visible(fn (Get $get) => (bool) $get('takes_leads'))
                ->helperText('Contoh: 60 dan 40 untuk dua agen. Kosongkan semua untuk dibagi rata. Jika sebagian sudah diisi, yang dikosongkan tidak mendapat lead baru. Total tidak harus 100, porsinya dihitung relatif.'),
        ]);
    }

    /** Whether this member is given new leads: agents by default, anyone else only when switched on. */
    public static function takesLeads(Membership $record): bool
    {
        return $record->takes_leads ?? ($record->role === Role::Agent);
    }

    /** The member's share of new leads as shown in the list, e.g. "60%", or null when they get none. */
    public static function effectiveShare(Membership $record): ?string
    {
        $tenant = app(CurrentTenant::class)->get();

        if ($tenant === null || $record->status !== 'active' || ! static::takesLeads($record)) {
            return null;
        }

        $weights = app(AssignLead::class)->weights($tenant->leadRecipients());
        $total = array_sum($weights);
        $mine = $weights[$record->user_id] ?? 0;

        return $total > 0 && $mine > 0 ? round($mine / $total * 100).'%' : null;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('user.name')->label('Nama')->searchable(),
            TextColumn::make('user.email')->label('Email')->searchable(),
            TextColumn::make('role')->label('Peran')->badge(),
            TextColumn::make('lead_share')->label('Porsi lead')
                ->state(fn (Membership $record) => static::effectiveShare($record))
                ->placeholder('-'),
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
