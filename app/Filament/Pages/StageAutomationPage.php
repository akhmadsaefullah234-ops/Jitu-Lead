<?php

namespace App\Filament\Pages;

use App\Automation\DigitalStageAutomationSettings;
use App\Automation\PropertyStageAutomation;
use App\Automation\PropertyStageAutomationSettings;
use App\Automation\StageAutomationSettings;
use App\Enums\Role;
use App\Filament\Concerns\InSettingsAndTools;
use App\Shipping\Carriers\ShipmentStatus;
use App\Shipping\Shipments\ParcelFollowUps;
use App\Shipping\Shipments\ParcelFollowUpSettings;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Which automatic stage moves an agency uses: a shop's (App\Automation\StageAutomation) or a property agency's
 * (App\Automation\PropertyStageAutomation), each with its own rules. A property agency made before its rules
 * existed sees them off with a "Nyalakan" button; nothing is switched on without an admin.
 */
class StageAutomationPage extends Page
{
    use InSettingsAndTools;

    protected string $view = 'filament.pages.stage-automation';

    protected static ?string $slug = 'otomatisasi-tahap';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static ?string $navigationLabel = 'Otomatisasi tahap';

    protected static ?string $title = 'Otomatisasi tahap';

    // Opened from "Pengaturan & Alat" (App\Filament\Support\SettingsCatalog), not from the sidebar.
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 14;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $current = app(CurrentTenant::class);

        return $current->roleOf(auth()->user()) === Role::Admin
            && (StageAutomationSettings::appliesTo($current->get()) || PropertyStageAutomationSettings::appliesTo($current->get())
                || DigitalStageAutomationSettings::appliesTo($current->get()));
    }

    public function isDigital(): bool
    {
        return DigitalStageAutomationSettings::appliesTo(app(CurrentTenant::class)->get());
    }

    public function isProperty(): bool
    {
        return PropertyStageAutomationSettings::appliesTo(app(CurrentTenant::class)->get());
    }

    /** A property agency that never switched its rules on. */
    public function waitingToStart(): bool
    {
        return $this->isProperty() && ! PropertyStageAutomationSettings::of(app(CurrentTenant::class)->get())->chosen();
    }

    public function mount(): void
    {
        $tenant = app(CurrentTenant::class)->get();
        $this->form->fill(match (true) {
            $this->isProperty() => PropertyStageAutomationSettings::of($tenant)->toArray(),
            $this->isDigital() => DigitalStageAutomationSettings::of($tenant)->toArray(),
            default => StageAutomationSettings::of($tenant)->toArray() + ['parcel' => ParcelFollowUpSettings::of($tenant)->toArray()],
        });
    }

    public function form(Schema $schema): Schema
    {
        $rules = match (true) {
            $this->isProperty() => PropertyStageAutomationSettings::RULES,
            $this->isDigital() => DigitalStageAutomationSettings::RULES,
            default => StageAutomationSettings::RULES,
        };
        $sections = [];
        $number = 0;

        foreach ($rules as $rule => [$label, $help, $numbers]) {
            $number++;
            $fields = [Toggle::make("{$rule}.enabled")->label('Aktif')->live()];

            foreach ($numbers as $key => $setting) {
                $fields[] = TextInput::make("{$rule}.{$key}")->label($setting[0])->numeric()->integer()->minValue(1)->maxValue(($setting[2] ?? 'hari') === 'jam' ? 8760 : 365)->suffix($setting[2] ?? 'hari')
                    ->required()->visible(fn (Get $get) => (bool) $get("{$rule}.enabled"));
            }

            if ($this->isProperty() && $rule === 'thanks') {
                $fields[] = Textarea::make('thanks.message')->label('Isi pesan')->rows(3)->maxLength(1000)
                    ->helperText('Bisa memakai {nama}, {agensi}, dan {properti}.')->visible(fn (Get $get) => (bool) $get('thanks.enabled'));
            }

            $sections[] = Section::make("{$number}. {$label}")->description($help)->columns(count($numbers) ? 3 : 1)->schema($fields)->compact();
        }

        if (! $this->isProperty() && ! $this->isDigital()) {
            $sections[] = $this->parcelSection();
        }

        return $schema->statePath('data')->components($sections)->disabled(fn () => $this->waitingToStart());
    }

    /** Follow-up harian paket gagal antar (App\Shipping\Shipments\ParcelFollowUps). */
    private function parcelSection(): Section
    {
        $variables = 'Bisa memakai '.implode(', ', array_map(fn ($v) => '{'.$v.'}', ParcelFollowUpSettings::VARIABLES)).'.';
        $known = fn (string $attribute, $value, \Closure $fail) => ($bad = ParcelFollowUpSettings::unknown((string) $value)) !== []
            ? $fail('Variabel tidak dikenal: '.implode(', ', array_map(fn ($v) => '{'.$v.'}', $bad)).'. '.$variables) : null;
        $texts = fn (string $name, string $label) => Repeater::make($name)->label($label)->schema([Textarea::make('text')->hiddenLabel()->rows(2)->maxLength(1000)->required()->rule(fn () => $known)])
            ->minItems(ParcelFollowUpSettings::MIN_TEXTS)->maxItems(10)->addActionLabel('Tambah variasi')->reorderable(false)
            ->helperText('Minimal '.ParcelFollowUpSettings::MIN_TEXTS.' variasi, dikirim bergiliran. '.$variables);
        $on = fn (Get $get) => (bool) $get('parcel.enabled');
        $number = count(StageAutomationSettings::RULES) + 1;

        return Section::make("{$number}. Paket gagal antar → follow-up WhatsApp sehari sekali")
            ->description('Pembeli yang paketnya dilaporkan kurir gagal diantar dikirimi satu pesan per hari di jam kirim, mulai sehari setelah pesan status "Gagal diantar". Berhenti bila pembeli membalas, lead dipindah ke Batal, paket dibatalkan, diterima, atau sudah kembali ke toko, CS menekan "Hentikan follow-up paket", atau batas pesan tercapai. Pembeli membalas atau batas tercapai: pemilik lead diberi tahu dan chat ditandai "Perlu tindakan" di Inbox. Saat pertama dinyalakan, hanya kegagalan '.ParcelFollowUpSettings::BACKLOG_DAYS.' hari terakhir yang ikut.')
            ->compact()->columns(3)->schema([
                Toggle::make('parcel.enabled')->label('Aktif')->live()->columnSpanFull(),
                Select::make('parcel.hour')->label('Jam kirim')->selectablePlaceholder(false)->required()->visible($on)
                    ->options(collect(range(ParcelFollowUpSettings::MIN_HOUR, ParcelFollowUpSettings::MAX_HOUR))->mapWithKeys(fn ($h) => [$h => sprintf('%02d.00', $h)])->all())
                    ->helperText('Zona waktu agensi ('.(app(CurrentTenant::class)->get()?->timezone ?: 'Asia/Jakarta').'). Bila terlewat, dikirim paling lambat pukul '.ParcelFollowUpSettings::SEND_UNTIL.'.00.'),
                TextInput::make('parcel.limit')->label('Batas jumlah pesan per paket')->numeric()->integer()->minValue(0)->maxValue(ParcelFollowUpSettings::MAX_LIMIT)->required()->live(onBlur: true)->visible($on)
                    ->helperText(fn (Get $get) => (string) $get('parcel.limit') === '0'
                        ? new HtmlString('<span style="color:var(--danger-600)">0 = tanpa batas. Pesan harian tanpa henti ke nomor yang tidak membalas berisiko membuat nomor WhatsApp Anda dilaporkan atau diblokir.</span>')
                        : '0 = tanpa batas (berisiko nomor diblokir).'),
                CheckboxList::make('parcel.days')->label('Hari kirim')->options(array_map(fn ($d) => $d, ParcelFollowUpSettings::DAY_NAMES))->columns(4)->required()->visible($on),
                $texts('parcel.texts', 'Teks follow-up gagal antar')->columnSpanFull()->visible($on),
                Toggle::make('parcel.returning')->label('Ikutkan paket yang sedang dikembalikan ke toko (retur)')->live()->visible($on)->columnSpanFull()
                    ->helperText('Dengan teks sendiri: tanyakan pembeli mau dikirim ulang atau dibatalkan.'),
                $texts('parcel.returning_texts', 'Teks follow-up paket retur')->columnSpanFull()->visible(fn (Get $get) => $on($get) && (bool) $get('parcel.returning')),
                Text::make(fn () => $this->backlogHtml())->columnSpanFull()->visible($on),
            ]);
    }

    /** Failed parcels left out of the follow-up (too old when it was switched on): a person handles them. */
    private function backlogHtml(): HtmlString
    {
        $list = ParcelFollowUps::backlog(ParcelFollowUpSettings::of(app(CurrentTenant::class)->get()));

        if ($list->isEmpty()) {
            return new HtmlString('');
        }

        $tz = app(CurrentTenant::class)->get()?->timezone ?: 'Asia/Jakarta';
        $rows = $list->take(50)->map(fn (array $r) => '<li><a style="text-decoration:underline" href="'.e(Inbox::getUrl(['lead' => $r['shipment']->lead_id])).'">'.e($r['shipment']->lead?->name ?? 'Lead #'.$r['shipment']->lead_id).'</a> · '
            .e(ShipmentStatus::label($r['shipment']->status)).' sejak '.e($r['failed_at']->copy()->timezone($tz)->format('d-m-Y')).' · resi '.e($r['shipment']->awb ?: '-').'</li>')->implode('');
        $more = $list->count() > 50 ? '<p>dan '.($list->count() - 50).' lainnya.</p>' : '';

        return new HtmlString('<div style="padding:.75rem 1rem;border-radius:.5rem;background:var(--warning-50);color:var(--warning-800)"><b>'.$list->count().' paket gagal lebih dari '
            .ParcelFollowUpSettings::BACKLOG_DAYS.' hari sebelum fitur dinyalakan</b> tidak ikut follow-up otomatis. Tangani manual:<ul style="list-style:disc;padding-left:1.25rem;margin-top:.5rem">'.$rows.'</ul>'.$more.'</div>');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('start')->label('Nyalakan')->icon(Heroicon::OutlinedPlay)
                ->visible(fn () => $this->waitingToStart())
                ->requiresConfirmation()->modalHeading('Nyalakan otomatisasi tahap?')
                ->modalDescription('Semua aturan di halaman ini menyala dengan nilai bawaannya, kecuali pesan terima kasih setelah survei. Lead yang sudah ada tidak diubah sekarang; untuk itu pakai "Terapkan ke lead yang ada" setelah ini.')
                ->action(function () {
                    PropertyStageAutomationSettings::store(app(CurrentTenant::class)->get(), PropertyStageAutomationSettings::defaults());
                    $this->mount();
                    Notification::make()->title('Otomatisasi tahap menyala')->success()->send();
                }),
            Action::make('catchUp')->label('Terapkan ke lead yang ada')->color('gray')->icon(Heroicon::OutlinedQueueList)
                ->visible(fn () => $this->isProperty() && ! $this->waitingToStart())
                ->modalHeading('Terapkan ke lead yang ada')
                ->modalDescription(fn () => $this->preview())
                ->modalSubmitActionLabel('Terapkan')
                ->action(function () {
                    $done = app(PropertyStageAutomation::class)->catchUp(app(CurrentTenant::class)->get(), apply: true);
                    Notification::make()->title(count($done).' perubahan diterapkan')->success()->send();
                }),
            Action::make('save')->label('Simpan')->action('save')->visible(fn () => ! $this->waitingToStart()),
        ];
    }

    /** What "Terapkan" would change, listed before anything is changed. */
    private function preview(): HtmlString
    {
        $changes = app(PropertyStageAutomation::class)->catchUp(app(CurrentTenant::class)->get(), apply: false);

        if ($changes === []) {
            return new HtmlString('Tidak ada lead yang perlu diubah menurut aturan yang menyala.');
        }

        $rows = collect($changes)->take(50)->map(fn (array $c) => '<li><b>'.e($c['lead']).'</b>: '.e($c['change']).' ('.e($c['reason']).')</li>')->implode('');
        $more = count($changes) > 50 ? '<p>dan '.(count($changes) - 50).' lainnya.</p>' : '';

        return new HtmlString('<p>'.count($changes).' perubahan berikut akan diterapkan. Setiap perpindahan tercatat di aktivitas lead sebagai "Pindah otomatis".</p><ul style="list-style:disc;padding-left:1.25rem;margin-top:.5rem">'.$rows.'</ul>'.$more);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $tenant = app(CurrentTenant::class)->get();

        match (true) {
            $this->isProperty() => PropertyStageAutomationSettings::store($tenant, PropertyStageAutomationSettings::clean($state)),
            // Kept under its own key, so a shop's or a property agency's earlier rules stay in the column untouched.
            $this->isDigital() => DigitalStageAutomationSettings::store($tenant, DigitalStageAutomationSettings::clean($state)),
            default => $tenant->forceFill(['stage_automation' => StageAutomationSettings::clean($state),
                'parcel_follow_up' => ParcelFollowUpSettings::clean((array) ($state['parcel'] ?? []), $tenant)])->save(),
        };

        Notification::make()->title('Otomatisasi tahap disimpan')->success()->send();
    }
}
