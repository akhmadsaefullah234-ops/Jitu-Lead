<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Support\CurrentTenant;
use App\Support\Industry;
use App\Tracking\CtwaReadiness;
use App\Tracking\PipelineConversions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Throwable;

/** "Siap iklan CTWA" (property agencies): App\Tracking\CtwaReadiness on a page, with a test event button. */
class CtwaReadinessPage extends Page
{
    protected string $view = 'filament.pages.ctwa-readiness';

    protected static ?string $slug = 'siap-iklan-ctwa';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static ?string $navigationLabel = 'Siap iklan CTWA';

    protected static ?string $title = 'Siap iklan Klik ke WhatsApp';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 16;

    public static function canAccess(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user()) === Role::Admin && Industry::current()->key === 'properti';
    }

    /** @return list<array{key: string, title: string, state: string, detail: string}> */
    public function checks(): array
    {
        return app(CtwaReadiness::class)->checks();
    }

    public function summary(): array
    {
        return app(CtwaReadiness::class)->summary();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')->label('Uji kirim event')->icon(Heroicon::OutlinedBeaker)
                ->modalHeading('Uji kirim event')->modalSubmitActionLabel('Kirim')
                ->modalDescription('Dikirim hanya ke platform yang kode uji event-nya diisi di Pelacakan iklan > Atur pelacakan, sehingga muncul di tab Test Events dan tidak dihitung sebagai konversi.')
                ->schema([Select::make('event')->label('Event')->options(PipelineConversions::EVENTS)->default('QualifiedLead')->required()->native(false)])
                ->action(function (array $data) {
                    try {
                        $ready = TrackingSettings::sendTestEvent((string) $data['event']);
                    } catch (Throwable $e) {
                        Notification::make()->title('Event uji gagal dikirim')->body('Periksa Pixel ID dan token.')->danger()->send();

                        return;
                    }

                    $ready === null
                        ? Notification::make()->title('Belum ada platform yang siap diuji')->body('Isi Pixel ID, token, dan kode uji event (dari tab Test Events) di Pelacakan iklan > Atur pelacakan dulu.')->warning()->send()
                        : Notification::make()->title('Event uji '.$data['event'].' dikirim ke '.implode(' dan ', $ready))->body('Periksa tab Test Events di Events Manager. Hasilnya tampil di daftar di bawah.')->success()->send();
                }),
            Action::make('tracking')->label('Pelacakan iklan')->color('gray')->url(fn () => TrackingSettings::getUrl()),
        ];
    }
}
