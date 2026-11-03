<?php

namespace App\Filament\Pages;

use App\Billing\PlanLimits;
use App\Enums\Role;
use App\Filament\Support\NoAutofill;
use App\Jobs\SendConversionEvents;
use App\Models\Stage;
use App\Models\TrackingSetting;
use App\Support\CurrentTenant;
use App\Support\Industry;
use App\Tracking\ConversionPayload;
use App\Tracking\PipelineConversions;
use App\Tracking\PixelEvents;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

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

    /** @return list<array{name: string, ok: bool, browser: bool, server: bool, hint: string, last: ?array}> */
    public function platforms(): array
    {
        $s = $this->settings();
        $last = (array) ($s?->last_results ?? []);

        $cards = [
            ['name' => 'Meta (Facebook & Instagram)', 'ok' => filled($s?->meta_pixel_id), 'browser' => filled($s?->meta_pixel_id), 'server' => filled($s?->credential('meta_capi_token')),
                'hint' => 'Event "'.($s?->eventFor('meta') ?? 'Lead').'" dari browser dan dari server, dengan ID yang sama agar tidak terhitung dua kali.', 'last' => $last['meta'] ?? null],
            ['name' => 'TikTok', 'ok' => filled($s?->tiktok_pixel_id), 'browser' => filled($s?->tiktok_pixel_id), 'server' => filled($s?->credential('tiktok_events_token')),
                'hint' => 'Event "'.($s?->eventFor('tiktok') ?? 'SubmitForm').'" dari browser dan dari server.', 'last' => $last['tiktok'] ?? null],
            ['name' => 'Google (GA4 / Google Ads)', 'ok' => filled($s?->google_tag_id), 'browser' => filled($s?->google_tag_id), 'server' => false,
                'hint' => 'Event "'.($s?->eventFor('google') ?? 'generate_lead').'", dan konversi Google Ads bila Anda mengisi label konversi. Dari browser saja.', 'last' => null],
        ];

        if ($s?->pipeline()['messaging'] || isset($last['meta_messaging'])) {
            $cards[] = ['name' => TrackingSetting::DESTINATIONS['meta_messaging'], 'ok' => (bool) $s?->pipeline()['messaging'], 'browser' => false, 'server' => (bool) $s?->pipeline()['messaging'],
                'hint' => 'Untuk chat dari iklan Klik ke WhatsApp yang masuk lewat nomor WhatsApp Business API resmi.', 'last' => $last['meta_messaging'] ?? null];
        }

        return $cards;
    }

    /** "Berhasil 3 Nov 09.20 (Purchase)" / "Gagal ... (HTTP 400)" for a card. */
    public static function lastResult(?array $last): ?string
    {
        if ($last === null || ! isset($last['at'])) {
            return null;
        }

        $at = Carbon::parse($last['at'])->timezone(config('app.timezone'))->translatedFormat('j M Y H.i');

        return ($last['ok'] ?? false ? 'Berhasil' : 'Gagal').' '.$at
            .(isset($last['event']) ? ' ('.$last['event'].(isset($last['status']) ? ', HTTP '.$last['status'] : '').')' : (isset($last['status']) ? ' (HTTP '.$last['status'].')' : ''));
    }

    /** @return array{enabled: bool, hot: ?string, stages: array<int, string>, messaging: bool} */
    public function pipeline(): array
    {
        $s = $this->settings();

        return $s?->pipeline_events !== null ? $s->pipeline() : PipelineConversions::defaults(Industry::current()->key);
    }

    /** "Order masuk: InitiateCheckout · Hot: Lead" for the page, or why nothing is sent. */
    public function pipelineSummary(): string
    {
        $p = $this->pipeline();
        $names = Stage::query()->whereIn('id', array_keys($p['stages']))->pluck('name', 'id');
        $parts = array_filter([
            ...array_map(fn ($id, $event) => isset($names[$id]) ? $names[$id].': '.$event : null, array_keys($p['stages']), $p['stages']),
            $p['hot'] ? 'Lead ditandai Hot: '.$p['hot'] : null,
        ]);

        return ($p['enabled'] ? 'Aktif. ' : 'Mati. ').($parts ? implode(' · ', $parts) : 'Belum ada tahap yang dipetakan.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')->label('Atur pelacakan')->visible(fn () => $this->lockedReason() === null)->icon(Heroicon::OutlinedCog6Tooth)
                ->modalHeading('Atur pelacakan iklan')->modalWidth('3xl')
                ->fillForm(fn () => ($this->settings()?->only(['meta_pixel_id', 'tiktok_pixel_id', 'google_tag_id', 'google_ads_label', 'meta_event', 'tiktok_event', 'google_event', 'cred']) ?? [])
                    + ['button_events' => array_replace(TrackingSetting::BUTTON_DEFAULTS, (array) $this->settings()?->button_events)])
                ->schema([
                    Section::make('Meta')->columns(2)->schema([
                        NoAutofill::text(TextInput::make('meta_pixel_id'))->label('Pixel ID')->regex('/^\d{6,20}$/')->placeholder('1234567890123456')->validationMessages(['regex' => 'Pixel ID berisi angka saja.']),
                        Select::make('meta_event')->label('Event saat formulir dikirim')->options(PixelEvents::options('meta'))->searchable()->default('Lead')->native(false)
                            ->helperText('Pilih "Lead" bila ragu. Ganti bila kampanye Anda dioptimalkan untuk event lain.'),
                        NoAutofill::secret(TextInput::make('cred.meta_capi_token')->label('Token Conversions API'))
                            ->placeholder(fn () => $this->settings()?->credential('meta_capi_token') ? 'Tersimpan. Kosongkan jika tidak diubah.' : null),
                        NoAutofill::text(TextInput::make('cred.meta_test_event_code'))->label('Kode uji event (opsional)')->maxLength(40)->helperText('Dari Events Manager, tab Test Events. Kosongkan setelah selesai menguji.'),
                    ]),
                    Section::make('TikTok')->columns(2)->schema([
                        NoAutofill::text(TextInput::make('tiktok_pixel_id'))->label('Pixel ID')->regex('/^[A-Za-z0-9]{8,40}$/')->validationMessages(['regex' => 'Pixel ID berisi huruf dan angka saja.']),
                        Select::make('tiktok_event')->label('Event saat formulir dikirim')->options(PixelEvents::options('tiktok'))->searchable()->default('SubmitForm')->native(false),
                        NoAutofill::secret(TextInput::make('cred.tiktok_events_token')->label('Token Events API'))
                            ->placeholder(fn () => $this->settings()?->credential('tiktok_events_token') ? 'Tersimpan. Kosongkan jika tidak diubah.' : null),
                        NoAutofill::text(TextInput::make('cred.tiktok_test_event_code'))->label('Kode uji event (opsional)')->maxLength(40),
                    ]),
                    Section::make('Google')->columns(2)->schema([
                        NoAutofill::text(TextInput::make('google_tag_id'))->label('ID tag')->regex('/^(G|AW|GT)-[A-Za-z0-9]{4,20}$/')->placeholder('G-XXXXXXXXXX atau AW-123456789')
                            ->validationMessages(['regex' => 'Awali dengan G-, AW-, atau GT-.']),
                        Select::make('google_event')->label('Event saat formulir dikirim')->options(PixelEvents::options('google'))->searchable()->default('generate_lead')->native(false),
                        NoAutofill::text(TextInput::make('google_ads_label'))->label('Label konversi Google Ads (opsional)')->regex('/^[A-Za-z0-9_-]{4,60}$/')
                            ->helperText('Hanya dipakai bila ID tag diawali AW-.'),
                    ]),
                    Section::make('Event bawaan saat tombol diklik')->columns(3)
                        ->description('Dipakai tombol di halaman landing dan formulir yang pixel-nya diatur "Ikuti Pengaturan Pelacakan". Tiap tombol bisa memilih event sendiri.')
                        ->schema(array_map(fn (string $platform) => Select::make('button_events.'.$platform)->label(PixelEvents::PLATFORMS[$platform])
                            ->options([PixelEvents::NONE => 'Tidak ada'] + PixelEvents::options($platform))->native(false)->searchable()->required(),
                            array_keys(PixelEvents::PLATFORMS))),
                ])
                ->action(function (array $data) {
                    $setting = TrackingSetting::current() ?? new TrackingSetting;
                    $buttonEvents = [];

                    foreach (array_keys(PixelEvents::PLATFORMS) as $platform) {
                        $choice = $data['button_events'][$platform] ?? null;
                        $buttonEvents[$platform] = $choice === PixelEvents::NONE || PixelEvents::isStandard($platform, $choice) ? $choice : TrackingSetting::BUTTON_DEFAULTS[$platform];
                    }
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
                        'meta_event' => $data['meta_event'] ?? 'Lead',
                        'tiktok_event' => $data['tiktok_event'] ?? 'SubmitForm',
                        'google_event' => $data['google_event'] ?? 'generate_lead',
                        'button_events' => $buttonEvents,
                        'credentials' => $credentials,
                    ])->save();

                    Notification::make()->title('Pelacakan disimpan')->success()->send();
                }),
            $this->pipelineAction(),
            $this->testAction(),
        ];
    }

    private function pipelineAction(): Action
    {
        $none = ['' => 'Tidak ada'];

        return Action::make('pipeline')->label('Event dari pipeline')->icon(Heroicon::OutlinedArrowsRightLeft)->color('gray')
            ->visible(fn () => $this->lockedReason() === null)
            ->modalHeading('Event dari pipeline')->modalWidth('2xl')
            ->modalDescription('Kirim event konversi ke Meta (dan TikTok bila terhubung) saat lead pindah ke tahap tertentu atau ditandai Hot, termasuk lead dari WhatsApp. Tiap lead mengirim tiap event sekali saja; Purchase boleh lagi untuk pesanan baru.')
            ->fillForm(function () {
                $p = $this->pipeline();

                return ['enabled' => $p['enabled'], 'hot' => $p['hot'] ?? '', 'messaging' => $p['messaging'],
                    'stages' => collect(Stage::query()->pluck('id'))->mapWithKeys(fn ($id) => ['s'.$id => $p['stages'][$id] ?? ''])->all()];
            })
            ->schema(fn () => [
                Toggle::make('enabled')->label('Kirim event dari pipeline')
                    ->helperText('Butuh Pixel ID dan token Conversions API Meta (atau Pixel ID dan token Events API TikTok) di "Atur pelacakan".'),
                Select::make('hot')->label('Lead ditandai Hot → event')->options($none + PipelineConversions::EVENTS)->native(false)->selectablePlaceholder(false),
                Section::make('Tahap → event')->description('Event dikirim saat lead masuk ke tahap itu, baik dipindah agen, otomatisasi tahap, maupun AI.')
                    ->schema(Stage::query()->orderBy('position')->orderBy('id')->get()->map(fn (Stage $stage) => Select::make('stages.s'.$stage->getKey())->label($stage->name)
                        ->options($none + PipelineConversions::EVENTS)->native(false)->selectablePlaceholder(false))->all()),
                Toggle::make('messaging')->label('Conversions API for Business Messaging (beta)')
                    ->helperText('Juga mengirim event ke dataset WhatsApp Business untuk chat dari iklan Klik ke WhatsApp yang masuk lewat nomor API resmi (butuh WhatsApp Business Account ID di Saluran WhatsApp). Tidak berlaku untuk nomor scan QR.'),
            ])
            ->action(function (array $data) {
                $setting = TrackingSetting::current() ?? new TrackingSetting;
                $valid = fn ($e) => is_string($e) && array_key_exists($e, PipelineConversions::EVENTS) ? $e : null;
                $stages = [];

                foreach (Stage::query()->pluck('id') as $id) {
                    if (($event = $valid($data['stages']['s'.$id] ?? null)) !== null) {
                        $stages[$id] = $event;
                    }
                }

                $setting->fill(['pipeline_events' => [
                    'enabled' => (bool) ($data['enabled'] ?? false), 'hot' => $valid($data['hot'] ?? null), 'stages' => $stages, 'messaging' => (bool) ($data['messaging'] ?? false),
                ]])->save();

                Notification::make()->title('Event dari pipeline disimpan')->success()->send();
            });
    }

    private function testAction(): Action
    {
        return Action::make('test')->label('Kirim event uji')->icon(Heroicon::OutlinedBeaker)->color('gray')
            ->visible(fn () => $this->lockedReason() === null)
            ->modalHeading('Kirim event uji')->modalSubmitActionLabel('Kirim')
            ->modalDescription('Dikirim hanya ke platform yang kode uji event-nya diisi di "Atur pelacakan", sehingga muncul di tab Test Events dan tidak dihitung sebagai konversi.')
            ->schema([Select::make('event')->label('Event')->options(PipelineConversions::EVENTS)->default('Lead')->required()->native(false)])
            ->action(function (array $data) {
                $settings = TrackingSetting::current();
                $ready = array_keys(array_filter([
                    'Meta' => filled($settings?->meta_pixel_id) && filled($settings?->credential('meta_capi_token')) && filled($settings?->credential('meta_test_event_code')),
                    'TikTok' => filled($settings?->tiktok_pixel_id) && filled($settings?->credential('tiktok_events_token')) && filled($settings?->credential('tiktok_test_event_code')),
                ]));

                if ($ready === []) {
                    Notification::make()->title('Belum ada platform yang siap diuji')->body('Isi Pixel ID, token, dan kode uji event (dari tab Test Events) di "Atur pelacakan" dulu.')->warning()->send();

                    return;
                }

                $event = (string) $data['event'];
                $payload = new ConversionPayload('test-'.Str::lower(Str::random(12)), time(), null, null, PipelineConversions::phoneHash('081200000000'), null, null, [],
                    ['meta' => $event, 'tiktok' => PipelineConversions::TIKTOK[$event] ?? null],
                    ['action_source' => 'system_generated', 'test' => true, 'user' => ['country' => hash('sha256', 'id'), 'external_id' => hash('sha256', 'uji')],
                        'custom' => ['currency' => PixelEvents::CURRENCY, 'value' => 100000]]);

                try {
                    SendConversionEvents::dispatchSync((int) app(CurrentTenant::class)->id(), $payload->toArray());
                    Notification::make()->title('Event uji '.$event.' dikirim ke '.implode(' dan ', $ready))->body('Periksa tab Test Events di Events Manager.')->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Event uji gagal dikirim')->body('Periksa Pixel ID dan token. Status terakhir tiap platform tampil di kartu di bawah.')->danger()->send();
                }
            });
    }
}
