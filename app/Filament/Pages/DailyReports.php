<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\Membership;
use App\Models\ReportDelivery;
use App\Models\Tenant;
use App\Models\User;
use App\Reports\DailyReportSender;
use App\Reports\ReportSettings;
use App\Reports\WhatsAppVerification;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Pengaturan > Laporan harian: each person switches their own WhatsApp report on or off, picks a time and days
 * (or follows the agency), the weekly and monthly summaries, and confirms the number it goes to; admins also set
 * the agency's default time and days. "Kirim contoh sekarang" sends yesterday's report at once, and the last 14
 * reports show how each went (WhatsApp, email, failed).
 */
class DailyReports extends Page
{
    protected string $view = 'filament.pages.daily-reports';

    protected static ?string $slug = 'laporan-harian';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static ?string $navigationLabel = 'Laporan harian';

    protected static ?string $title = 'Laporan harian WhatsApp';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 2;

    /** @var array<string, mixed> */
    public ?array $mine = [];

    /** @var array<string, mixed> */
    public ?array $agency = [];

    public string $number = '';

    public string $code = '';

    public static function canAccess(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user()) !== null;
    }

    public function mount(): void
    {
        $this->mineForm->fill(ReportSettings::member($this->membership()));
        $this->agencyForm->fill(ReportSettings::agency($this->tenant()));
        $this->number = (string) (app(WhatsAppVerification::class)->pending($this->user()) ?? $this->user()->whatsapp);
    }

    public function isAdmin(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user()) === Role::Admin;
    }

    public function mineForm(Schema $schema): Schema
    {
        $agency = ReportSettings::agency($this->tenant());
        $agent = ! (app(CurrentTenant::class)->roleOf(auth()->user())?->seesAllLeads() ?? false);

        return $schema->statePath('mine')->components([
            Section::make('Laporan untuk saya')->compact()->columns(['default' => 1, 'md' => 2])
                ->description($agent ? 'Sebagai agen, laporan Anda hanya berisi angka lead milik Anda.' : 'Angka seluruh agensi, sama dengan Dasbor periode "Kemarin".')
                ->schema([
                    Toggle::make('enabled')->label('Kirim laporan harian ke WhatsApp saya')->columnSpanFull(),
                    Select::make('time')->label('Jam kirim')->options(ReportSettings::times())
                        ->placeholder('Ikut agensi ('.$agency['time'].')')->helperText('Waktu '.$this->tenant()->timezone.'.'),
                    Select::make('days')->label('Hari kirim')->options(ReportSettings::DAYS)
                        ->placeholder('Ikut agensi ('.ReportSettings::DAYS[$agency['days']].')'),
                    Toggle::make('weekly')->label('Ringkasan mingguan (Senin pagi, 7 hari terakhir)'),
                    Toggle::make('monthly')->label('Ringkasan bulanan (tanggal 1, bulan lalu)'),
                ]),
        ]);
    }

    public function agencyForm(Schema $schema): Schema
    {
        return $schema->statePath('agency')->components([
            Section::make('Bawaan agensi')->compact()->columns(['default' => 1, 'md' => 2])
                ->description('Berlaku untuk anggota yang tidak memilih jam dan hari sendiri. Laporan aktif sejak awal untuk admin, tidak untuk agen.')
                ->schema([
                    Select::make('time')->label('Jam kirim')->options(ReportSettings::times())->required()->selectablePlaceholder(false),
                    Select::make('days')->label('Hari kirim')->options(ReportSettings::DAYS)->required()->selectablePlaceholder(false),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sample')->label('Kirim contoh sekarang')->icon(Heroicon::OutlinedPaperAirplane)->color('gray')
                ->action(fn () => $this->sendSample()),
            Action::make('save')->label('Simpan')->action(fn () => $this->save()),
        ];
    }

    public function save(): void
    {
        ReportSettings::saveMember($this->membership(), $this->mineForm->getState());

        if ($this->isAdmin()) {
            ReportSettings::saveAgency($this->tenant(), $this->agencyForm->getState());
        }

        Notification::make()->title('Pengaturan laporan disimpan')->success()->send();
    }

    public function sendSample(): void
    {
        $key = 'report-sample:'.$this->user()->getKey();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            Notification::make()->title('Contoh sudah dikirim beberapa kali')->body('Coba lagi dalam '.max(1, (int) ceil(RateLimiter::availableIn($key) / 60)).' menit.')->warning()->send();

            return;
        }

        RateLimiter::hit($key, 3600);
        $row = app(DailyReportSender::class)->sample($this->tenant(), $this->user());

        match ($row->status) {
            ReportDelivery::SENT => Notification::make()->title('Contoh laporan terkirim ke WhatsApp Anda')->success()->send(),
            ReportDelivery::EMAIL => Notification::make()->title('Contoh laporan dikirim lewat email')->body($row->error)->warning()->send(),
            default => Notification::make()->title('Contoh laporan gagal dikirim')->body($row->error)->danger()->send(),
        };
    }

    public function sendCode(): void
    {
        $problem = app(WhatsAppVerification::class)->send($this->user(), $this->number);
        $this->code = '';

        $problem === null
            ? Notification::make()->title('Kode dikirim ke WhatsApp Anda')->body('Ketik 6 digit kodenya di bawah.')->success()->send()
            : Notification::make()->title('Kode belum terkirim')->body($problem)->danger()->send();
    }

    public function verifyCode(): void
    {
        $problem = app(WhatsAppVerification::class)->verify($this->user(), $this->code);
        $this->code = '';

        if ($problem === null) {
            $this->number = (string) $this->user()->refresh()->whatsapp;
            Notification::make()->title('Nomor WhatsApp terverifikasi')->success()->send();

            return;
        }

        Notification::make()->title('Belum terverifikasi')->body($problem)->danger()->send();
    }

    public function codePending(): bool
    {
        return app(WhatsAppVerification::class)->pending($this->user()) !== null;
    }

    public function verified(): bool
    {
        return $this->user()->hasVerifiedWhatsapp();
    }

    /** The last 14 reports: the whole agency's for an admin, one's own otherwise. */
    public function history()
    {
        return ReportDelivery::query()->with('user:id,name')->where('tenant_id', $this->tenant()->getKey())
            ->when(! $this->isAdmin(), fn ($q) => $q->where('user_id', $this->user()->getKey()))
            ->latest('id')->limit(14)->get();
    }

    private function user(): User
    {
        return auth()->user();
    }

    public function tenant(): Tenant
    {
        return app(CurrentTenant::class)->get();
    }

    private function membership(): Membership
    {
        return Membership::query()->where('tenant_id', $this->tenant()->getKey())->where('user_id', $this->user()->getKey())->firstOrFail();
    }
}
