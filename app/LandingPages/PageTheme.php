<?php

namespace App\LandingPages;

use App\Models\LandingPage;
use App\Support\Industry;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * "Gaya halaman" of a landing page, kept in landing_pages.layout next to the spacing (PageLayout):
 *
 *     {theme: 'gelap', accent: '#e9c27b'}
 *
 * Without a theme (or "terang") the page is drawn exactly as before: nothing here prints any CSS. "Gelap" prints one
 * extra stylesheet after the normal one, with a dark background, darker cards and the accent colour on the main
 * buttons, heading lines, check marks, prices and badges. The editor offers it to "Produk digital" agencies only.
 */
class PageTheme
{
    public const THEMES = ['terang' => 'Terang (bawaan)', 'gelap' => 'Gelap'];

    public const ACCENT = '#e9c27b';

    /** The dark palette: page, cards, cards on a tinted section, text, muted text, lines. */
    public const DARK = ['bg' => '#171020', 'card' => '#221731', 'card2' => '#2d2040', 'ink' => '#f5ecf4', 'mute' => '#b9a9c4', 'line' => '#46345f'];

    /** Text on the accent when it reads well enough on it (App\LandingPages\PageTheme::onAccent). */
    public const ON_DARK = '#171020';

    public static function section(): Section
    {
        return Section::make('Gaya halaman')->statePath('layout')
            ->description('Gelap: latar ungu tua, kartu lebih gelap, dan warna aksen untuk tombol utama, garis judul, centang, harga, dan label. Terang: tampilan biasa.')
            ->visible(fn () => Industry::current()->key === 'digital')
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    Select::make('theme')->label('Gaya halaman')->options(self::THEMES)->placeholder('Terang (bawaan)')->native(false)->live(),
                    ColorPicker::make('accent')->label('Warna aksen')->placeholder(self::ACCENT)->regex(LandingPage::COLOR_PATTERN)
                        ->helperText('Kosongkan untuk emas lembut ('.self::ACCENT.'). Teks di atas tombol aksen dipilih otomatis agar tetap terbaca.')
                        ->visible(fn (Get $get) => $get('theme') === 'gelap'),
                ]),
            ]);
    }

    /** Only known values: no key at all for the light page, so old pages keep an empty layout. */
    public static function clean(array $layout): array
    {
        $out = [];

        if (($layout['theme'] ?? null) === 'gelap') {
            $out['theme'] = 'gelap';
        }

        if (is_string($layout['accent'] ?? null) && preg_match(LandingPage::COLOR_PATTERN, $layout['accent'])) {
            $out['accent'] = strtolower($layout['accent']);
        }

        return $out;
    }

    public static function isDark(mixed $layout): bool
    {
        return is_array($layout) && ($layout['theme'] ?? null) === 'gelap';
    }

    public static function accent(mixed $layout): string
    {
        $accent = is_array($layout) ? ($layout['accent'] ?? null) : null;

        return is_string($accent) && preg_match(LandingPage::COLOR_PATTERN, $accent) ? strtolower($accent) : self::ACCENT;
    }

    /**
     * The text colour for a button in this accent: the dark page colour when it reads well (the default gold), else
     * whichever of white and black contrasts more, so every accent stays readable.
     */
    public static function onAccent(string $accent): string
    {
        if (self::contrast($accent, self::ON_DARK) >= 4.5) {
            return self::ON_DARK;
        }

        return self::contrast($accent, '#ffffff') >= self::contrast($accent, '#000000') ? '#ffffff' : '#000000';
    }

    /** WCAG contrast ratio of two #rrggbb colours (1 to 21). */
    public static function contrast(string $a, string $b): float
    {
        [$la, $lb] = [self::luminance($a), self::luminance($b)];

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $c = array_map(function (string $h) {
            $v = hexdec($h) / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    /** The dark stylesheet, or '' for a light page (which then prints nothing new). */
    public static function css(mixed $layout): string
    {
        if (! self::isDark($layout)) {
            return '';
        }

        $d = self::DARK;
        $ac = self::accent($layout);
        $on = self::onAccent($ac);

        return ":root{--ink:{$d['ink']};--mute:{$d['mute']};--line:{$d['line']};--soft:{$d['card']};--bg:{$d['bg']};--card:{$d['card']};--card2:{$d['card2']};--ac:{$ac};--on-ac:{$on};color-scheme:dark}"
            .'body{background:var(--bg);color:var(--ink)}'
            .'.top{background:var(--bg);border-bottom-color:var(--ac)}.dock,.buybar{background:var(--card);border-top-color:var(--line)}'
            .'section.blk.alt,section.blk.bg-light{background:var(--card)}section.blk.bg-dark{background:#0e0915;color:var(--ink)}'
            .'.card,details.q,.formbox,table.det,.near li,.legal li,.kpr .out,.count div,.chip,.ic-grid li,.stats div,.kc{background:var(--card);color:var(--ink);border-color:var(--line)}'
            .'.alt .card,.bg-light .card,.alt details.q,.bg-light details.q,.alt .formbox,.bg-light .formbox,.alt .kc,.bg-light .kc{background:var(--card2)}'
            .'.formbox{box-shadow:0 10px 30px rgba(0,0,0,.4)}.formbox h2,details.q summary{color:var(--ink)}.card p,details.q p,.formbox .intro{color:var(--mute)}'
            .'h2::after,.bg-brand h2::after{background:var(--ac)}'
            .'.btn,body .jb-brand,.hero .btn,body .jb-light{background:var(--ac);color:var(--on-ac);border-color:var(--ac)}.kc{border-top-color:var(--kc)}'
            .'.jb:focus-visible{outline-color:var(--ac)}'
            .'.dot,.ic-i{background:color-mix(in srgb,var(--ac) 16%,transparent);color:var(--ac)}'
            .'.pts li::before,.legal li::before,.assures li::before,.bg-brand .assures li::before,.bg-dark .assures li::before,.cmp-b li span{color:var(--ac)}'
            .'.cmp-b{border-color:var(--ac)}.cmp-a li span{color:#fca5a5}'
            .'.ribbon,.tier-n{background:var(--ac);color:var(--on-ac)}.tier-bar i.on{background:var(--ac)}'
            .'.price strong,.prc strong,.buybar-t strong,.unit .p,.near b,.count b,.stats b,.kpr .out strong{color:var(--ac)}'
            .'.prose a{color:var(--ac)}.sl-dots button[aria-current="true"]::before{background:var(--ac)}'
            .'body .lf-f,body .lf-g legend,body .lf-sum,body .rg-o{color:var(--ink)}'
            .'body .lf-f input,body .lf-f textarea,body .lf-f select,body .lf-o,body .lf-qb,body .rg-l,body .lf-sum{background:var(--card2);color:var(--ink);border-color:var(--line)}'
            .'body .lf-f input::placeholder,body .lf-f textarea::placeholder{color:var(--mute);opacity:1}'
            .'body .lf-f input:focus,body .lf-f textarea:focus,body .lf-f select:focus,body .lf-o input:focus-visible{outline-color:color-mix(in srgb,var(--ac) 45%,transparent);border-color:var(--ac)}'
            .'body .lf-o:has(input:checked){border-color:var(--ac);background:color-mix(in srgb,var(--ac) 14%,var(--card2))}body .lf-o input{accent-color:var(--ac)}'
            .'body .rg-o[aria-selected=true],body .rg-o:hover,body .lf-qb:active{background:color-mix(in srgb,var(--ac) 14%,var(--card2))}'
            .'body .lf-note,body .rg-none,body .lf-sum small,body .lf-back,body .lf-prog,.foot,.mute{color:var(--mute)}body .lf-bar{background:var(--line)}body .lf-bar i{background:var(--ac)}'
            .'body .lf-err{color:#fca5a5}body .lf-warn{color:#fcd34d}.preview-tag{background:#0e0915}';
    }
}
