<?php

namespace App\LandingPages;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;

/**
 * An image carousel, as its own section ("Slider gambar") or as the picture of the hero. Swipes with a finger
 * (CSS scroll snapping), so it works before and without its small script; the script adds arrows, dots,
 * autoplay and looping.
 *
 *     {slides: [{image, alt, title, caption, link, has_button, button}],
 *      autoplay, interval, arrows, dots, loop, per_mobile, per_tablet, per_desktop, gap, effect: slide|fade,
 *      ratio, fit, radius}
 */
class Slider
{
    /** The editor fields; $prefix keeps the hero's slider settings apart from its own fields. */
    public static function fields(string $prefix = ''): array
    {
        return [
            Repeater::make($prefix.'slides')->label('Slide')->maxItems(12)->defaultItems(0)->collapsible()->reorderable()->reorderableWithDragAndDrop()
                ->addActionLabel('Tambah slide')->itemLabel(fn (array $state) => $state['title'] ?? $state['alt'] ?? null)
                ->schema([
                    Sections::upload('image')->label('Gambar')->required(),
                    Grid::make(['default' => 1, 'sm' => 2])->schema([
                        TextInput::make('alt')->label('Teks alternatif (untuk pembaca layar)')->maxLength(150)->placeholder('Foto produk tampak depan'),
                        TextInput::make('link')->label('Tautan saat gambar diklik (opsional)')->url()->maxLength(500)->placeholder('https://...'),
                        TextInput::make('title')->label('Judul (opsional)')->maxLength(100),
                        TextInput::make('caption')->label('Keterangan (opsional)')->maxLength(200),
                    ]),
                    Toggle::make('has_button')->label('Tambahkan tombol di slide ini')->live(),
                    Group::make()->statePath('button')->visible(fn (Get $get) => (bool) $get('has_button'))->schema(Buttons::fields()),
                ]),
            Fieldset::make('Pengaturan slider')->columns(['default' => 2, 'sm' => 3])->schema([
                Toggle::make($prefix.'autoplay')->label('Geser otomatis')->default(false)->live(),
                TextInput::make($prefix.'interval')->label('Jeda (detik)')->numeric()->minValue(2)->maxValue(15)->default(5)
                    ->visible(fn (Get $get) => (bool) $get($prefix.'autoplay')),
                Toggle::make($prefix.'arrows')->label('Panah')->default(true),
                Toggle::make($prefix.'dots')->label('Titik penanda')->default(true),
                Toggle::make($prefix.'loop')->label('Berulang (loop)')->default(true),
                Select::make($prefix.'effect')->label('Efek')->options(['slide' => 'Geser', 'fade' => 'Pudar (fade)'])->default('slide')->native(false),
                Select::make($prefix.'per_mobile')->label('Slide terlihat di HP')->options([1 => '1', 2 => '2'])->default(1)->native(false),
                Select::make($prefix.'per_tablet')->label('Di tablet')->options([1 => '1', 2 => '2', 3 => '3'])->default(2)->native(false),
                Select::make($prefix.'per_desktop')->label('Di PC')->options([1 => '1', 2 => '2', 3 => '3', 4 => '4'])->default(1)->native(false),
                TextInput::make($prefix.'gap')->label('Jarak antar slide')->numeric()->minValue(0)->maxValue(48)->default(12)->suffix('px'),
                Select::make($prefix.'ratio')->label('Rasio gambar')->options(BlockStyle::RATIOS)->default('16:9')->native(false),
                Select::make($prefix.'fit')->label('Cara isi')->options(BlockStyle::FITS)->default('cover')->native(false),
                TextInput::make($prefix.'radius')->label('Sudut')->numeric()->minValue(0)->maxValue(40)->default(12)->suffix('px'),
            ]),
        ];
    }

    /**
     * What the view draws: slides that have a picture, and the settings within their limits.
     *
     * @return array{slides: list<array<string, mixed>>, autoplay: bool, interval: int, arrows: bool, dots: bool, loop: bool,
     *               per: array{0: int, 1: int, 2: int}, gap: int, fade: bool, ratio: ?string, fit: string, radius: int}
     */
    public static function view(array $p, int|string|null $tenantId, string $prefix = ''): array
    {
        $get = fn (string $k, mixed $default = null) => $p[$prefix.$k] ?? $default;
        $int = fn (string $k, int $default, int $min, int $max) => is_numeric($get($k)) ? max($min, min($max, (int) $get($k))) : $default;
        $slides = [];

        foreach ((array) $get('slides', []) as $s) {
            if (is_array($s) && ($src = Embeds::image($s['image'] ?? null, $tenantId))) {
                $slides[] = [
                    'src' => $src,
                    'alt' => trim((string) ($s['alt'] ?? '')) ?: trim((string) ($s['title'] ?? '')),
                    'title' => trim((string) ($s['title'] ?? '')),
                    'caption' => trim((string) ($s['caption'] ?? '')),
                    'link' => Buttons::safeUrl($s['link'] ?? null),
                    'button' => ! empty($s['has_button']) && is_array($s['button'] ?? null) && filled($s['button']['label'] ?? null) ? $s['button'] : null,
                ];
            }
        }

        $fade = $get('effect') === 'fade';
        $ratio = (string) $get('ratio', '16:9');

        return [
            'slides' => $slides,
            'autoplay' => (bool) $get('autoplay', false),
            'interval' => $int('interval', 5, 2, 15),
            'arrows' => (bool) $get('arrows', true),
            'dots' => (bool) $get('dots', true),
            'loop' => (bool) $get('loop', true),
            'per' => $fade ? [1, 1, 1] : [$int('per_mobile', 1, 1, 2), $int('per_tablet', 2, 1, 3), $int('per_desktop', 1, 1, 4)],
            'gap' => $int('gap', 12, 0, 48),
            'fade' => $fade,
            'ratio' => array_key_exists($ratio, BlockStyle::RATIOS) && $ratio !== 'auto' ? str_replace(':', '/', $ratio) : null,
            'fit' => $get('fit') === 'contain' ? 'contain' : 'cover',
            'radius' => $int('radius', 12, 0, 40),
        ];
    }
}
