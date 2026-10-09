<?php

namespace App\Filament\Pages;

use App\Billing\PlanLimits;
use App\Enums\Role;
use App\Filament\Support\NoAutofill;
use App\Models\TrackingSetting;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * Pixel and tag ids for Meta, TikTok and Google, and the tokens that let the
 * server report each new lead too (so blockers and iOS do not hide it).
 */
class TrackingSettings extends Page
{
    protected string $view = 'filament.pages.tracking-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $navigationLabel = 'Pelacakan iklan';

    protected static ?string $title = 'Pelacakan iklan';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 15;

    public static function canAccess(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user()) === Role::Admin;
    }

    /** Why this plan cannot use ad pixels, or null when it can. */
    public function lockedReason(): ?string
    {
        return PlanLimits::current()?->denyFeature('pixels');
    }

    public function settings(): ?TrackingSetting
    {
        return TrackingSetting::current();
    }

    /** @return list<array{name: string, ok: bool, browser: bool, server: bool, hint: string}> */
    public function platforms(): array
    {
        $s = $this->settings();

        return [
            ['name' => 'Meta (Facebook & Instagram)', 'ok' => filled($s?->meta_pixel_id), 'browser' => filled($s?->meta_pixel_id), 'server' => filled($s?->credential('meta_capi_token')),
                'hint' => 'Event "Lead" dari browser dan dari server, dengan ID yang sama agar tidak terhitung dua kali.'],
            ['name' => 'TikTok', 'ok' => filled($s?->tiktok_pixel_id), 'browser' => filled($s?->tiktok_pixel_id), 'server' => filled($s?->credential('tiktok_events_token')),
                'hint' => 'Event "SubmitForm" dari browser dan dari server.'],
            ['name' => 'Google (GA4 / Google Ads)', 'ok' => filled($s?->google_tag_id), 'browser' => filled($s?->google_tag_id), 'server' => false,
                'hint' => 'Event "generate_lead", dan konversi Google Ads bila Anda mengisi label konversi. Dari browser saja.'],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')->label('Atur pelacakan')->visible(fn () => $this->lockedReason() === null)->icon(Heroicon::OutlinedCog6Tooth)
                ->modalHeading('Atur pelacakan iklan')->modalWidth('3xl')
                ->fillForm(fn () => $this->settings()?->only(['meta_pixel_id', 'tiktok_pixel_id', 'google_tag_id', 'google_ads_label', 'cred']) ?? [])
                ->schema([
                    Section::make('Meta')->columns(2)->schema([
                        NoAutofill::text(TextInput::make('meta_pixel_id'))->label('Pixel ID')->regex('/^\d{6,20}$/')->placeholder('1234567890123456')->validationMessages(['regex' => 'Pixel ID berisi angka saja.']),
                        NoAutofill::secret(TextInput::make('cred.meta_capi_token')->label('Token Conversions API'))
                            ->placeholder(fn () => $this->settings()?->credential('meta_capi_token') ? 'Tersimpan. Kosongkan jika tidak diubah.' : null),
                        NoAutofill::text(TextInput::make('cred.meta_test_event_code'))->label('Kode uji event (opsional)')->maxLength(40)->helperText('Dari Events Manager, tab Test Events. Kosongkan setelah selesai menguji.'),
                    ]),
                    Section::make('TikTok')->columns(2)->schema([
                        NoAutofill::text(TextInput::make('tiktok_pixel_id'))->label('Pixel ID')->regex('/^[A-Za-z0-9]{8,40}$/')->validationMessages(['regex' => 'Pixel ID berisi huruf dan angka saja.']),
                        NoAutofill::secret(TextInput::make('cred.tiktok_events_token')->label('Token Events API'))
                            ->placeholder(fn () => $this->settings()?->credential('tiktok_events_token') ? 'Tersimpan. Kosongkan jika tidak diubah.' : null),
                        NoAutofill::text(TextInput::make('cred.tiktok_test_event_code'))->label('Kode uji event (opsional)')->maxLength(40),
                    ]),
                    Section::make('Google')->columns(2)->schema([
                        NoAutofill::text(TextInput::make('google_tag_id'))->label('ID tag')->regex('/^(G|AW|GT)-[A-Za-z0-9]{4,20}$/')->placeholder('G-XXXXXXXXXX atau AW-123456789')
                            ->validationMessages(['regex' => 'Awali dengan G-, AW-, atau GT-.']),
                        NoAutofill::text(TextInput::make('google_ads_label'))->label('Label konversi Google Ads (opsional)')->regex('/^[A-Za-z0-9_-]{4,60}$/')
                            ->helperText('Hanya dipakai bila ID tag diawali AW-.'),
                    ]),
                ])
                ->action(function (array $data) {
                    $setting = TrackingSetting::current() ?? new TrackingSetting;
                    $credentials = $setting->credentials ?? [];

                    foreach ((array) ($data['cred'] ?? []) as $key => $value) {
                        if (filled($value)) {
                            $credentials[$key] = trim((string) $value);
                        } elseif (str_ends_with($key, 'test_event_code')) {
                            unset($credentials[$key]);
                        }
                    }

                    $setting->fill([
                        'meta_pixel_id' => $data['meta_pixel_id'] ?? null,
                        'tiktok_pixel_id' => $data['tiktok_pixel_id'] ?? null,
                        'google_tag_id' => $data['google_tag_id'] ?? null,
                        'google_ads_label' => $data['google_ads_label'] ?? null,
                        'credentials' => $credentials,
                    ])->save();

                    Notification::make()->title('Pelacakan disimpan')->success()->send();
                }),
        ];
    }
}
