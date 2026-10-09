<?php

namespace App\Filament\Resources\Team\Pages;

use App\Billing\Deny;
use App\Billing\PlanLimits;
use App\Enums\Role;
use App\Filament\Resources\Team\TeamResource;
use App\Models\Membership;
use App\Models\User;
use App\Support\CurrentTenant;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\DB;

class ManageTeam extends ManageRecords
{
    protected static string $resource = TeamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')->label('Tambah anggota')->icon('heroicon-o-user-plus')
                ->visible(fn () => auth()->user()?->can('create', Membership::class))
                ->modalHeading('Tambah anggota tim')
                ->modalDescription('Akun baru dibuat dengan kata sandi sementara. Berikan ke anggota dan minta menggantinya.')
                ->schema([
                    TextInput::make('name')->label('Nama')->required()->maxLength(120),
                    TextInput::make('email')->label('Email')->email()->required()->maxLength(160)->unique('users', 'email')
                        ->validationMessages(['unique' => 'Email ini sudah punya akun.']),
                    TextInput::make('password')->label('Kata sandi sementara')->password()->revealable()->required()->minLength(10),
                    Select::make('role')->label('Peran')->options(Role::class)->default(Role::Agent->value)->required(),
                ])
                ->before(function (Action $action) {
                    if ($denied = PlanLimits::current()?->denyAdding('users')) {
                        Deny::notify($denied);
                        $action->halt();
                    }
                })
                ->action(function (array $data) {
                    $tenantId = app(CurrentTenant::class)->id();

                    DB::transaction(function () use ($data, $tenantId) {
                        $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
                        Membership::create(['tenant_id' => $tenantId, 'user_id' => $user->getKey(), 'role' => $data['role'], 'status' => 'active']);
                    });

                    Notification::make()->title('Anggota ditambahkan')->success()->send();
                }),
        ];
    }
}
