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
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\DB;

class ManageTeam extends ManageRecords
{
    protected static string $resource = TeamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('distribution')->label('Pemindahan lead')->icon('heroicon-o-arrows-right-left')->color('gray')
                ->visible(fn () => auth()->user()?->can('create', Membership::class))
                ->modalHeading('Pemindahan lead yang belum ditanggapi')
                ->modalDescription('Jika agen tidak menanggapi lead baru dalam waktu tertentu, lead bisa pindah ke agen lain. Pembagian lead baru mengikuti porsi (%) tiap anggota di tabel ini. Chat dan lead yang pernah ditanggapi tidak dipindah.')
                ->fillForm(fn () => ['mode' => app(CurrentTenant::class)->get()?->leadReassignMode() ?? 'manual',
                    'minutes' => app(CurrentTenant::class)->get()?->leadWaitMinutes() ?? 30,
                    'max' => app(CurrentTenant::class)->get()?->leadMaxMoves() ?? 2])
                ->schema([
                    Select::make('mode')->label('Cara pemindahan')->required()->native(false)->live()
                        ->options([
                            'manual' => 'Manual: admin memindahkan sendiri dari daftar lead',
                            'auto' => 'Otomatis: sistem memindahkan bila tidak ditanggapi',
                        ]),
                    TextInput::make('minutes')->label('Batas waktu tanpa tanggapan (menit)')->numeric()->required()->minValue(5)->maxValue(10080)
                        ->helperText('Dipakai untuk penanda "Belum ditanggapi" di daftar lead, dan untuk pemindahan otomatis.'),
                    TextInput::make('max')->label('Maksimal berpindah per lead')->numeric()->required()->minValue(1)->maxValue(10)
                        ->visible(fn (Get $get) => $get('mode') === 'auto')
                        ->helperText('Setelah sebanyak ini, lead tidak dipindah lagi supaya tidak berputar terus.'),
                ])
                ->action(function (array $data) {
                    app(CurrentTenant::class)->get()?->forceFill([
                        'lead_reassign_mode' => $data['mode'],
                        'lead_reassign_minutes' => (int) $data['minutes'],
                        'lead_reassign_max' => (int) ($data['max'] ?? 2),
                    ])->save();

                    Notification::make()->title('Pengaturan disimpan')->success()->send();
                }),
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
