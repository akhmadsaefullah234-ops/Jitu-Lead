<?php

namespace App\LandingPages;

use App\Models\LandingPage;
use App\Models\Tenant;
use App\Models\TrackingSetting;
use App\Tracking\PixelEvents;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;

/**
 * A button on a landing page or form: its text, look, what it does when clicked, and its own pixel events.
 *
 *     {label, color, size, icon, action: whatsapp|page|scroll|tel,
 *      wa_target: agency|rotate|manual, wa_number, wa_message,     (whatsapp)
 *      page_kind: landing|url, landing_page_id, url, new_tab,      (page)
 *      tel,                                                       (tel)
 *      pixel: {...}}                                              (App\Tracking\PixelEvents)
 *
 * "rotate" sends the visitor to /w/{agency}, which picks the next agent's WhatsApp in turn.
 */
class Buttons
{
    public const COLORS = ['brand' => 'Warna utama', 'wa' => 'Hijau WhatsApp', 'dark' => 'Gelap', 'light' => 'Putih', 'outline' => 'Garis (transparan)'];

    public const SIZES = ['sm' => 'Kecil', 'md' => 'Sedang', 'lg' => 'Besar'];

    public const ACTIONS = ['whatsapp' => 'Buka WhatsApp', 'page' => 'Buka halaman', 'scroll' => 'Gulir ke formulir', 'tel' => 'Telepon'];

    public const WA_TARGETS = ['agency' => 'Nomor WhatsApp agensi / halaman', 'rotate' => 'Agen bergiliran (round-robin)', 'manual' => 'Nomor yang saya isi'];

    /** Small inline icons (stroke paths), so nothing is downloaded. */
    public const ICONS = [
        'none' => ['Tanpa ikon', null],
        'whatsapp' => ['WhatsApp', 'M3 21l1.6-4.6A8.5 8.5 0 1 1 8 20zM9 8.5c0 3.5 3 6.5 6.5 6.5l1-1.5-2-1-1 1c-1.2-.5-2.5-1.8-3-3l1-1-1-2z'],
        'phone' => ['Telepon', 'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2'],
        'arrow' => ['Panah', 'M5 12h14M13 6l6 6-6 6'],
        'down' => ['Panah ke bawah', 'M12 5v14M6 13l6 6 6-6'],
        'chat' => ['Chat', 'M4 5h16v11H8l-4 4z'],
        'cart' => ['Keranjang', 'M3 4h2l2.5 11h11L21 8H6.5M9 20h.01M18 20h.01'],
        'calendar' => ['Kalender', 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4'],
        'mail' => ['Email', 'M3 6h18v12H3zM3 7l9 6 9-6'],
        'download' => ['Unduh', 'M12 4v11M7 10l5 5 5-5M5 20h14'],
    ];

    /** A list of buttons in the editor. */
    public static function repeater(string $name, string $label, array $default, int $max = 3): Repeater
    {
        return Repeater::make($name)->label($label)->schema(self::fields())->default($default)->maxItems($max)
            ->collapsible()->collapsed()->reorderableWithButtons()->addActionLabel('Tambah tombol')
            ->itemLabel(fn (array $state) => trim(($state['label'] ?? 'Tombol').' · '.(self::ACTIONS[$state['action'] ?? ''] ?? '')));
    }

    /** @return list<mixed> */
    public static function fields(bool $withScroll = true): array
    {
        $is = fn (string $action) => fn (Get $get) => $get('action') === $action;

        return [
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                TextInput::make('label')->label('Teks tombol')->required()->maxLength(40),
                Select::make('action')->label('Aksi saat diklik')->options($withScroll ? self::ACTIONS : array_diff_key(self::ACTIONS, ['scroll' => 1]))
                    ->default('whatsapp')->required()->native(false)->live(),
                Select::make('color')->label('Warna')->options(self::COLORS)->default('brand')->native(false),
                Select::make('size')->label('Ukuran')->options(self::SIZES)->default('md')->native(false),
                Select::make('icon')->label('Ikon')->options(array_map(fn ($i) => $i[0], self::ICONS))->default('none')->native(false),
            ]),
            Grid::make(['default' => 1, 'sm' => 2])->visible($is('whatsapp'))->schema([
                Select::make('wa_target')->label('Nomor tujuan')->options(self::WA_TARGETS)->default('agency')->native(false)->live(),
                TextInput::make('wa_number')->label('Nomor WhatsApp')->tel()->maxLength(20)->placeholder('08123456789')
                    ->visible(fn (Get $get) => $get('wa_target') === 'manual')->required(fn (Get $get) => $get('action') === 'whatsapp' && $get('wa_target') === 'manual'),
                TextInput::make('wa_message')->label('Pesan otomatis')->maxLength(300)->columnSpanFull()
                    ->helperText('Boleh memakai {judul} (judul halaman), {produk} (properti/produk halaman), dan {halaman} (alamat halaman).'),
            ]),
            Grid::make(['default' => 1, 'sm' => 2])->visible($is('page'))->schema([
                Select::make('page_kind')->label('Halaman tujuan')->options(['landing' => 'Halaman landing milik agensi', 'url' => 'Alamat (URL) bebas'])->default('landing')->native(false)->live(),
                Select::make('landing_page_id')->label('Halaman landing')->options(fn () => LandingPage::query()->orderBy('title')->pluck('title', 'id'))->searchable()
                    ->visible(fn (Get $get) => $get('page_kind') !== 'url'),
                TextInput::make('url')->label('URL')->url()->maxLength(500)->placeholder('https://...')
                    ->visible(fn (Get $get) => $get('page_kind') === 'url'),
                Toggle::make('new_tab')->label('Buka di tab baru')->default(false),
            ]),
            TextInput::make('tel')->label('Nomor telepon')->tel()->maxLength(20)->visible($is('tel'))->required(fn (Get $get) => $get('action') === 'tel'),
            PixelEvents::fieldset('pixel', 'Pixel saat tombol diklik', 'bawaan klik tombol'),
        ];
    }

    /**
     * Where a button goes, or null when it cannot work (no number, no page).
     *
     * @param  array{tenant: Tenant, wa: ?string, vars: array<string, string>, anchor: string}  $ctx
     * @return array{href: string, new_tab: bool, action: string}|null
     */
    public static function link(array $button, array $ctx): ?array
    {
        $action = $button['action'] ?? 'whatsapp';
        $message = self::fill((string) ($button['wa_message'] ?? ''), $ctx['vars'] ?? []);

        $href = match ($action) {
            'whatsapp' => match ($button['wa_target'] ?? 'agency') {
                'rotate' => route('go.wa', $ctx['tenant']->slug).($message !== '' ? '?text='.rawurlencode($message) : ''),
                'manual' => ($n = PageData::digits($button['wa_number'] ?? null)) ? PageData::waLink($n, $message) : null,
                default => filled($ctx['wa'] ?? null) ? PageData::waLink($ctx['wa'], $message) : null,
            },
            'page' => ($button['page_kind'] ?? 'landing') === 'url'
                ? self::safeUrl($button['url'] ?? null)
                : self::landingUrl($button['landing_page_id'] ?? null),
            'scroll' => $ctx['anchor'] ?? '#daftar',
            'tel' => ($n = PageData::digits($button['tel'] ?? null)) ? 'tel:+'.$n : null,
            default => null,
        };

        if ($href === null) {
            return null;
        }

        return ['href' => $href, 'new_tab' => $action === 'page' && ! empty($button['new_tab']), 'action' => $action];
    }

    /** The pixel events a click fires, as JSON for the data-px attribute, or null. */
    public static function pixel(array $button, ?TrackingSetting $tracking): ?string
    {
        $resolved = PixelEvents::resolve($button['pixel'] ?? null, $tracking?->buttonDefaults() ?? [], $tracking);

        return $resolved === null ? null : json_encode($resolved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function iconPath(?string $icon): ?string
    {
        return self::ICONS[$icon ?? 'none'][1] ?? null;
    }

    /** Replaces {judul}, {produk}... with their values; unknown ones are dropped. */
    public static function fill(string $text, array $vars): string
    {
        $text = preg_replace_callback('/\{([a-z0-9_]+)\}/i', fn ($m) => (string) ($vars[Str::lower($m[1])] ?? ''), $text);

        // An answer left empty should not leave "warna  ukuran ," behind.
        return trim(preg_replace(['/[ \t]{2,}/', '/[ \t]+([,.])/'], [' ', '$1'], (string) $text));
    }

    /** Only http(s) addresses, so a button can never run script. */
    public static function safeUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        return preg_match('#^https?://[^\s"<>]+$#i', $url) ? $url : null;
    }

    private static function landingUrl(mixed $id): ?string
    {
        $page = is_numeric($id) ? LandingPage::query()->find((int) $id) : null;

        return $page?->isPublished() ? $page->publicUrl() : null;
    }
}
