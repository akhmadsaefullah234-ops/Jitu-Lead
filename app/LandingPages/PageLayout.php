<?php

namespace App\LandingPages;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Page-wide look of a landing page (landing_pages.layout): for now the space above and below every section, on
 * PC and on HP. It is the default of each section; the "Gaya" panel of a section overrides it. Empty keeps the
 * spacing pages always had (48px), so existing pages do not change.
 *
 *     {spacing: ''|tight|normal|loose|custom, spacing_px, spacing_mobile: ''|tight|normal|loose|custom, spacing_mobile_px}
 */
class PageLayout
{
    public const SPACING = ['tight' => 'Rapat', 'normal' => 'Normal', 'loose' => 'Lega', 'custom' => 'Angka (px)'];

    /** Pixels above and below a section for each preset; "normal" is the spacing pages always had. */
    public const SPACING_PX = ['tight' => 24, 'normal' => 48, 'loose' => 80];

    public const MAX_PX = 200;

    public static function section(): Section
    {
        $preset = fn (string $name, string $label) => Select::make($name)->label($label)->options(self::SPACING)->placeholder('Bawaan (48 px)')->native(false)->live();
        $px = fn (string $name, string $preset) => TextInput::make($name)->label('Jarak (px)')->numeric()->minValue(0)->maxValue(self::MAX_PX)->suffix('px')
            ->visible(fn (Get $get) => $get($preset) === 'custom')->required(fn (Get $get) => $get($preset) === 'custom');

        return Section::make('Jarak antar bagian')->statePath('layout')
            ->description('Ruang di atas dan di bawah setiap bagian (kecuali bagian utama/hero). Jarak yang diatur di panel Gaya sebuah bagian menimpa pengaturan ini.')
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    $preset('spacing', 'Di PC'),
                    $px('spacing_px', 'spacing'),
                    $preset('spacing_mobile', 'Di HP'),
                    $px('spacing_mobile_px', 'spacing_mobile'),
                ]),
            ]);
    }

    /**
     * Only known, valid values.
     *
     * @return array<string, mixed>
     */
    public static function clean(mixed $layout): array
    {
        $l = is_array($layout) ? $layout : [];
        $out = [];

        foreach (['spacing', 'spacing_mobile'] as $key) {
            $preset = (string) ($l[$key] ?? '');

            if ($preset === 'custom' && is_numeric($l[$key.'_px'] ?? null)) {
                $out[$key] = 'custom';
                $out[$key.'_px'] = max(0, min(self::MAX_PX, (int) $l[$key.'_px']));
            } elseif (isset(self::SPACING_PX[$preset])) {
                $out[$key] = $preset;
            }
        }

        return $out;
    }

    /** Pixels for one device, or null when it keeps the default. */
    public static function spacing(mixed $layout, bool $mobile = false): ?int
    {
        $l = self::clean($layout);
        $key = $mobile ? 'spacing_mobile' : 'spacing';

        return match ($l[$key] ?? null) {
            null => null,
            'custom' => $l[$key.'_px'],
            default => self::SPACING_PX[$l[$key]],
        };
    }

    /** The page-wide CSS; '' when nothing is set. Sections with their own spacing win (their rules carry an id). */
    public static function css(mixed $layout): string
    {
        $css = '';

        if (($pc = self::spacing($layout)) !== null) {
            $css .= "section.blk{padding-top:{$pc}px;padding-bottom:{$pc}px}";
        }

        if (($hp = self::spacing($layout, true)) !== null) {
            $css .= "@media (max-width:767px){section.blk{padding-top:{$hp}px;padding-bottom:{$hp}px}}";
        }

        return $css;
    }
}
