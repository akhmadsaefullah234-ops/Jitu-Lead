<?php

namespace App\LandingPages;

use App\Tracking\PixelEvents;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The page's own buttons outside its sections (landing_pages.navigation), set in the "Tombol & navigasi" tab:
 *
 *     {header: {show, button},                     the button beside the logo
 *      dock: {mode: off|1|2, first, second},       the bar fixed at the bottom on phones
 *      wa_float: {show, target, number, message, pixel},   the round WhatsApp button in the corner
 *      auto_form: bool}                            the agency's main form below pages without a form section
 *
 * Buttons have the shape of App\LandingPages\Buttons. Everything starts switched off, for every kind of business:
 * visitors are asked to act from the page's own content (a form section or buttons).
 */
class Navigation
{
    public const DOCK_MODES = ['off' => 'Mati', '1' => '1 tombol', '2' => '2 tombol'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'header' => ['show' => false, 'button' => ['label' => 'Daftar', 'action' => 'scroll', 'color' => 'brand', 'icon' => 'none']],
            'dock' => [
                'mode' => 'off',
                'first' => ['label' => 'WhatsApp', 'action' => 'whatsapp', 'wa_target' => 'agency', 'wa_message' => 'Halo, saya tertarik dengan {judul}.', 'color' => 'wa', 'icon' => 'whatsapp'],
                'second' => ['label' => 'Daftar', 'action' => 'scroll', 'color' => 'brand', 'icon' => 'none'],
            ],
            'wa_float' => ['show' => false, 'target' => 'agency', 'number' => null, 'message' => 'Halo, saya tertarik dengan {judul}.', 'pixel' => null],
            'auto_form' => true,
        ];
    }

    /**
     * The stored settings filled up with the defaults and cleaned, so the page and the editor can rely on every key.
     *
     * @return array<string, mixed>
     */
    public static function of(mixed $stored): array
    {
        $s = is_array($stored) ? $stored : [];
        $d = self::defaults();
        $button = fn (mixed $b, array $default) => is_array($b) && filled($b['label'] ?? null) ? $b : $default;
        $float = is_array($s['wa_float'] ?? null) ? $s['wa_float'] : [];

        return [
            'header' => [
                'show' => (bool) ($s['header']['show'] ?? false),
                'button' => $button($s['header']['button'] ?? null, $d['header']['button']),
            ],
            'dock' => [
                'mode' => array_key_exists((string) ($s['dock']['mode'] ?? ''), self::DOCK_MODES) ? (string) $s['dock']['mode'] : 'off',
                'first' => $button($s['dock']['first'] ?? null, $d['dock']['first']),
                'second' => $button($s['dock']['second'] ?? null, $d['dock']['second']),
            ],
            'wa_float' => [
                'show' => (bool) ($float['show'] ?? false),
                'target' => array_key_exists((string) ($float['target'] ?? ''), Buttons::WA_TARGETS) ? $float['target'] : 'agency',
                'number' => filled($float['number'] ?? null) ? (string) $float['number'] : null,
                'message' => (string) ($float['message'] ?? $d['wa_float']['message']),
                'pixel' => is_array($float['pixel'] ?? null) ? $float['pixel'] : null,
            ],
            'auto_form' => (bool) ($s['auto_form'] ?? true),
        ];
    }

    /**
     * The buttons to draw, each only when it can work (a WhatsApp button needs a number, a page button a page).
     *
     * @param  array{tenant: mixed, wa: ?string, vars: array<string, string>, anchor: string}  $ctx
     * @return array{header: ?array<string, mixed>, dock: list<array<string, mixed>>, float: ?array<string, mixed>}
     */
    public static function resolve(mixed $stored, array $ctx): array
    {
        $nav = self::of($stored);
        $works = fn (array $b) => Buttons::link($b, $ctx) !== null;

        $header = $nav['header']['show'] && $works($nav['header']['button']) ? array_merge($nav['header']['button'], ['size' => 'sm']) : null;

        $dock = match ($nav['dock']['mode']) {
            '1' => [$nav['dock']['first']],
            '2' => [$nav['dock']['first'], $nav['dock']['second']],
            default => [],
        };
        $dock = array_values(array_map(fn ($b) => array_merge($b, ['size' => 'md']), array_filter($dock, $works)));

        $float = null;
        if ($nav['wa_float']['show']) {
            $b = ['label' => 'WhatsApp', 'action' => 'whatsapp', 'wa_target' => $nav['wa_float']['target'], 'wa_number' => $nav['wa_float']['number'],
                'wa_message' => $nav['wa_float']['message'], 'pixel' => $nav['wa_float']['pixel']];
            $float = $works($b) ? $b : null;
        }

        return ['header' => $header, 'dock' => $dock, 'float' => $float];
    }

    /** The "Tombol & navigasi" tab. */
    public static function schema(): array
    {
        $dockButton = fn (string $key, string $label, array $modes) => Fieldset::make($label)->statePath($key)->columns(1)
            ->visible(fn (Get $get) => in_array((string) $get('mode'), $modes, true))
            ->schema(Buttons::fields(withSize: false));

        return [
            Group::make()->statePath('navigation')->schema([
                Section::make('Tombol di header')->compact()->statePath('header')
                    ->description('Tombol kecil di samping logo, selalu terlihat saat halaman digulir.')
                    ->schema([
                        Toggle::make('show')->label('Tampilkan tombol di header')->default(false)->live(),
                        Group::make()->statePath('button')->visible(fn (Get $get) => (bool) $get('show'))->schema(Buttons::fields(withSize: false)),
                    ]),
                Section::make('Bar tombol bawah (HP)')->compact()->statePath('dock')
                    ->description('Bar yang menempel di bawah layar HP. Tidak tampil di layar lebar.')
                    ->schema([
                        Radio::make('mode')->label('Bar bawah')->options(self::DOCK_MODES)->default('off')->inline()->live(),
                        $dockButton('first', 'Tombol 1', ['1', '2']),
                        $dockButton('second', 'Tombol 2', ['2']),
                    ]),
                Section::make('Tombol WhatsApp melayang')->compact()->statePath('wa_float')
                    ->description('Tombol bulat di pojok kanan bawah, sebagai pengganti bar bawah.')
                    ->schema([
                        Toggle::make('show')->label('Tampilkan tombol WhatsApp melayang')->default(false)->live(),
                        Group::make()->visible(fn (Get $get) => (bool) $get('show'))->schema([
                            Select::make('target')->label('Nomor tujuan')->options(Buttons::WA_TARGETS)->default('agency')->native(false)->live(),
                            TextInput::make('number')->label('Nomor WhatsApp')->tel()->maxLength(20)->placeholder('08123456789')
                                ->visible(fn (Get $get) => $get('target') === 'manual')
                                ->required(fn (Get $get) => (bool) $get('show') && $get('target') === 'manual'),
                            TextInput::make('message')->label('Pesan otomatis')->maxLength(300)
                                ->helperText('Boleh memakai {judul}, {produk}, dan {halaman}.'),
                            PixelEvents::fieldset('pixel', 'Pixel saat tombol diklik', 'bawaan klik tombol'),
                        ]),
                    ]),
                Section::make('Formulir otomatis')->compact()->schema([
                    Toggle::make('auto_form')->label('Tambahkan formulir utama di bawah halaman bila halaman tidak punya bagian Formulir')->default(true),
                ]),
            ]),
        ];
    }

    /**
     * What is saved: the cleaned settings without the parts nobody can see (closed pickers keep their values).
     *
     * @return array<string, mixed>
     */
    public static function clean(mixed $state): array
    {
        return self::of($state);
    }
}
