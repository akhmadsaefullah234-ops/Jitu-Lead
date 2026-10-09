<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Privacy\TenantEraser;
use App\Privacy\TenantExport;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The agency admin's rights over the agency's own data (UU PDP): download all of
 * it, or delete the agency, without asking the platform owner. Open even when
 * the plan has ended, because these rights do not depend on paying.
 */
class DataPrivacy extends Page
{
    protected string $view = 'filament.pages.data-privacy';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Data & privasi';

    protected static ?string $title = 'Data & privasi';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 30;

    public static function canAccess(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user()) === Role::Admin;
    }

    protected function getHeaderActions(): array
    {
        return [$this->exportAction(), $this->deleteAction()];
    }

    private function exportAction(): Action
    {
        return Action::make('export')->label('Unduh semua data (ZIP)')->icon(Heroicon::OutlinedArrowDownTray)
            ->action(function () {
                $tenant = app(CurrentTenant::class)->get();
                $key = 'tenant-export:'.$tenant->getKey();

                if (RateLimiter::tooManyAttempts($key, 3)) {
                    Notification::make()->title('Terlalu sering')->body('Ekspor dibatasi 3 kali per jam. Coba lagi nanti.')->warning()->send();

                    return null;
                }

                RateLimiter::hit($key, 3600);
                Log::info('Data agensi diekspor', ['tenant_id' => $tenant->getKey(), 'user_id' => auth()->id()]);
                $path = app(TenantExport::class)($tenant);

                return response()->download($path, 'data-'.$tenant->slug.'-'.now()->format('Ymd').'.zip')->deleteFileAfterSend();
            });
    }

    private function deleteAction(): Action
    {
        return Action::make('deleteTenant')->label('Hapus agensi dan semua datanya')->icon(Heroicon::OutlinedTrash)->color('danger')
            ->modalHeading('Hapus agensi ini secara permanen?')
            ->modalDescription('Semua lead, percakapan WhatsApp, properti, landing page, pengaturan, dan chat support agensi ini dihapus seketika dan TIDAK bisa dikembalikan. Akun anggota yang hanya terdaftar di agensi ini ikut dihapus; akun Anda sendiri tetap ada. Langganan yang sedang berjalan tidak dikembalikan. Unduh dulu datanya bila masih diperlukan. Salinan di cadangan baru hilang setelah masa simpan cadangan berlalu.')
            ->modalSubmitActionLabel('Hapus permanen')
            ->schema(fn () => [
                TextInput::make('confirm')->label('Ketik alamat ruang kerja ('.app(CurrentTenant::class)->get()?->slug.') untuk memastikan')
                    ->required()->in([app(CurrentTenant::class)->get()?->slug])->validationMessages(['in' => 'Tidak sama dengan alamat ruang kerja.']),
                TextInput::make('password')->label('Kata sandi Anda')->password()->required()->rule('current_password'),
            ])
            ->action(function () {
                abort_unless(static::canAccess(), 403);

                app(TenantEraser::class)(app(CurrentTenant::class)->get(), auth()->user());

                Notification::make()->title('Agensi dihapus')->success()->send();

                return redirect('/app');
            });
    }
}
