<?php

namespace App\LandingPages;

use App\Models\LandingPage;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The "Gaya" panel of a section (props.style), turned into CSS scoped to that one section. Every value is picked
 * from a list, clamped to a range, or checked as a colour, so nothing typed reaches the stylesheet as is. An empty
 * style draws the section exactly as before. Phone values (style.mobile) override the others below 768px; columns and
 * the media position only apply from 768px up unless the phone has its own.
 *
 *     {align, heading_align, button_align, heading_size, heading_px, text_size, text_px, heading_weight, text_color,
 *      heading_color, line_height, bg_type: color|gradient|image, bg_color, bg_color2, bg_angle, bg_image, overlay,
 *      bg_position, pad_top, pad_bottom, gap, width, columns, media_pos, valign, img_width, img_max_h, ratio, fit,
 *      radius, shadow, img_align, btn_size, btn_full_mobile, btn_style, btn_color, btn_radius, btn_icon_pos,
 *      hide_mobile, hide_desktop, animation, mobile: {align, heading_size, heading_px, text_size, text_px, pad_top,
 *      pad_bottom, gap, columns, media_pos, img_width, img_max_h, ratio, btn_size}}
 */
class BlockStyle
{
    public const ALIGN = ['left' => 'Kiri', 'center' => 'Tengah', 'right' => 'Kanan'];

    public const HEADING = ['s' => 'S', 'm' => 'M', 'l' => 'L', 'xl' => 'XL'];

    private const HEADING_CSS = ['s' => '1.25rem', 'm' => '1.6rem', 'l' => '2.1rem', 'xl' => '2.75rem'];

    private const TEXT_CSS = ['s' => '.9rem', 'm' => '1rem', 'l' => '1.125rem', 'xl' => '1.3rem'];

    public const WEIGHTS = ['400' => 'Normal', '600' => 'Agak tebal', '700' => 'Tebal', '800' => 'Sangat tebal'];

    public const WIDTHS = ['narrow' => 'Sempit', 'normal' => 'Normal', 'wide' => 'Lebar', 'full' => 'Penuh'];

    private const WIDTH_CSS = ['narrow' => '44rem', 'normal' => '64rem', 'wide' => '80rem', 'full' => 'none'];

    public const BACKGROUNDS = ['' => 'Bawaan', 'color' => 'Warna', 'gradient' => 'Gradien', 'image' => 'Gambar'];

    public const POSITIONS = ['center' => 'Tengah', 'top' => 'Atas', 'bottom' => 'Bawah', 'left' => 'Kiri', 'right' => 'Kanan'];

    public const MEDIA = ['left' => 'Kiri teks', 'right' => 'Kanan teks', 'top' => 'Di atas teks', 'bottom' => 'Di bawah teks'];

    public const VALIGN = ['start' => 'Atas', 'center' => 'Tengah', 'end' => 'Bawah'];

    public const RATIOS = ['auto' => 'Bebas', '1:1' => '1:1', '4:3' => '4:3', '16:9' => '16:9', '9:16' => '9:16'];

    public const FITS = ['cover' => 'Penuhi (cover)', 'contain' => 'Utuh (contain)'];

    public const BUTTON_SIZES = ['s' => 'S', 'm' => 'M', 'l' => 'L'];

    private const BUTTON_CSS = ['s' => ['.45rem .9rem', '.875rem', '2.25rem'], 'm' => ['.75rem 1.3rem', '1rem', '2.9rem'], 'l' => ['1rem 1.6rem', '1.1rem', '3.4rem']];

    public const ANIMATIONS = ['' => 'Tanpa animasi', 'fade' => 'Muncul perlahan (fade)', 'slide' => 'Naik perlahan (slide)'];

    private const JUSTIFY = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'];

    // ---- Editor ---------------------------------------------------------------------------------------------------

    public static function section(): Section
    {
        $pick = fn (string $name, string $label, array $options) => Select::make($name)->label($label)->options($options)->placeholder('Bawaan')->native(false);
        $px = fn (string $name, string $label, int $min, int $max, string $suffix = 'px') => TextInput::make($name)->label($label)->numeric()->minValue($min)->maxValue($max)->suffix($suffix)->placeholder('Bawaan');
        $grid = fn (array $schema) => Grid::make(['default' => 1, 'sm' => 2])->schema($schema);

        return Section::make('Gaya')->icon('heroicon-o-paint-brush')->collapsible()->collapsed()->compact()->statePath('style')
            ->description('Kosongkan untuk tampilan bawaan. Pengaturan HP menimpa pengaturan PC di layar kecil.')
            ->schema([
                Tabs::make('gaya')->tabs([
                    Tab::make('Teks')->schema([$grid([
                        $pick('align', 'Perataan bagian', self::ALIGN),
                        $pick('heading_align', 'Perataan judul', self::ALIGN),
                        $pick('heading_size', 'Ukuran judul', self::HEADING),
                        $px('heading_px', 'atau ukuran judul (px)', 12, 96),
                        $pick('text_size', 'Ukuran teks', self::HEADING),
                        $px('text_px', 'atau ukuran teks (px)', 10, 40),
                        $pick('heading_weight', 'Ketebalan judul', self::WEIGHTS),
                        $px('line_height', 'Jarak baris', 1, 3, '×')->step(0.1),
                        ColorPicker::make('heading_color')->label('Warna judul'),
                        ColorPicker::make('text_color')->label('Warna teks'),
                    ])]),
                    Tab::make('Latar & jarak')->schema([$grid([
                        Select::make('bg_type')->label('Latar')->options(self::BACKGROUNDS)->default('')->native(false)->selectablePlaceholder(false)->live(),
                        ColorPicker::make('bg_color')->label('Warna latar')->visible(fn (Get $get) => in_array($get('bg_type'), ['color', 'gradient'], true)),
                        ColorPicker::make('bg_color2')->label('Warna kedua gradien')->visible(fn (Get $get) => $get('bg_type') === 'gradient'),
                        $px('bg_angle', 'Arah gradien', 0, 360, '°')->visible(fn (Get $get) => $get('bg_type') === 'gradient'),
                        Sections::upload('bg_image')->label('Gambar latar')->visible(fn (Get $get) => $get('bg_type') === 'image'),
                        $px('overlay', 'Lapisan gelap', 0, 90, '%')->visible(fn (Get $get) => $get('bg_type') === 'image'),
                        $pick('bg_position', 'Posisi gambar latar', self::POSITIONS)->visible(fn (Get $get) => $get('bg_type') === 'image'),
                        $px('pad_top', 'Jarak atas', 0, 240),
                        $px('pad_bottom', 'Jarak bawah', 0, 240),
                        $px('gap', 'Jarak antar elemen', 0, 80),
                        $pick('width', 'Lebar konten', self::WIDTHS),
                    ])]),
                    Tab::make('Tata letak & gambar')->schema([$grid([
                        $pick('columns', 'Jumlah kolom (PC)', ['1' => '1', '2' => '2', '3' => '3', '4' => '4']),
                        $pick('media_pos', 'Posisi gambar/media (PC)', self::MEDIA),
                        $pick('valign', 'Perataan vertikal', self::VALIGN),
                        $pick('img_align', 'Perataan gambar', self::ALIGN),
                        $px('img_width', 'Lebar gambar', 10, 100, '%'),
                        $px('img_max_h', 'Tinggi maksimal gambar', 40, 1200),
                        $pick('ratio', 'Rasio gambar', self::RATIOS),
                        $pick('fit', 'Cara isi gambar', self::FITS),
                        $px('radius', 'Sudut gambar', 0, 60),
                        Toggle::make('shadow')->label('Bayangan gambar')->inline(false),
                    ])]),
                    Tab::make('Tombol')->schema([$grid([
                        $pick('button_align', 'Perataan tombol', self::ALIGN),
                        $pick('btn_size', 'Ukuran tombol', self::BUTTON_SIZES),
                        $pick('btn_style', 'Gaya tombol', ['solid' => 'Solid', 'outline' => 'Garis']),
                        ColorPicker::make('btn_color')->label('Warna tombol'),
                        $px('btn_radius', 'Sudut tombol', 0, 40),
                        $pick('btn_icon_pos', 'Posisi ikon', ['left' => 'Kiri', 'right' => 'Kanan']),
                        Toggle::make('btn_full_mobile')->label('Tombol selebar layar di HP')->inline(false),
                    ])]),
                    Tab::make('HP')->schema([
                        Grid::make(['default' => 1, 'sm' => 2])->statePath('mobile')->schema([
                            $pick('align', 'Perataan bagian', self::ALIGN),
                            $pick('columns', 'Jumlah kolom', ['1' => '1', '2' => '2', '3' => '3', '4' => '4']),
                            $pick('heading_size', 'Ukuran judul', self::HEADING),
                            $px('heading_px', 'atau ukuran judul (px)', 12, 96),
                            $pick('text_size', 'Ukuran teks', self::HEADING),
                            $px('text_px', 'atau ukuran teks (px)', 10, 40),
                            $px('pad_top', 'Jarak atas', 0, 240),
                            $px('pad_bottom', 'Jarak bawah', 0, 240),
                            $px('gap', 'Jarak antar elemen', 0, 80),
                            $pick('media_pos', 'Posisi gambar/media', ['top' => 'Di atas teks', 'bottom' => 'Di bawah teks']),
                            $px('img_width', 'Lebar gambar', 10, 100, '%'),
                            $px('img_max_h', 'Tinggi maksimal gambar', 40, 1200),
                            $pick('ratio', 'Rasio gambar', self::RATIOS),
                            $pick('btn_size', 'Ukuran tombol', self::BUTTON_SIZES),
                        ]),
                    ]),
                    Tab::make('Tampilan')->schema([$grid([
                        Toggle::make('hide_mobile')->label('Sembunyikan di HP')->inline(false),
                        Toggle::make('hide_desktop')->label('Sembunyikan di PC')->inline(false),
                        $pick('animation', 'Animasi masuk', array_filter(self::ANIMATIONS)),
                    ])]),
                ]),
            ]);
    }

    // ---- Page -----------------------------------------------------------------------------------------------------

    /** Whether the section has any style set (sections without one are drawn exactly as before). */
    public static function has(mixed $style): bool
    {
        return self::clean($style) !== [];
    }

    /**
     * Only the known, valid values; everything else is dropped.
     *
     * @return array<string, mixed>
     */
    public static function clean(mixed $style): array
    {
        $s = is_array($style) ? $style : [];
        $out = self::values($s, false);

        if (($s['bg_type'] ?? '') === 'image' && ($img = self::path($s['bg_image'] ?? null)) !== null) {
            $out['bg_image'] = $img;
        }

        $mobile = self::values(is_array($s['mobile'] ?? null) ? $s['mobile'] : [], true);

        if ($mobile !== []) {
            $out['mobile'] = $mobile;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function values(array $s, bool $mobile): array
    {
        $enum = fn (string $k, array $list) => array_key_exists((string) ($s[$k] ?? ''), $list) && ($s[$k] ?? '') !== '' ? (string) $s[$k] : null;
        $num = fn (string $k, float $min, float $max) => is_numeric($s[$k] ?? null) ? max($min, min($max, (float) $s[$k])) : null;
        $color = fn (string $k) => preg_match(LandingPage::COLOR_PATTERN, (string) ($s[$k] ?? '')) ? strtolower($s[$k]) : null;
        $cols = ['1' => 1, '2' => 1, '3' => 1, '4' => 1];

        $out = [
            'align' => $enum('align', self::ALIGN),
            'heading_size' => $enum('heading_size', self::HEADING),
            'heading_px' => $num('heading_px', 12, 96),
            'text_size' => $enum('text_size', self::HEADING),
            'text_px' => $num('text_px', 10, 40),
            'pad_top' => $num('pad_top', 0, 240),
            'pad_bottom' => $num('pad_bottom', 0, 240),
            'gap' => $num('gap', 0, 80),
            'columns' => $enum('columns', $cols),
            'media_pos' => $enum('media_pos', $mobile ? ['top' => 1, 'bottom' => 1] : self::MEDIA),
            'img_width' => $num('img_width', 10, 100),
            'img_max_h' => $num('img_max_h', 40, 1200),
            'ratio' => $enum('ratio', self::RATIOS),
            'btn_size' => $enum('btn_size', self::BUTTON_SIZES),
        ];

        if (! $mobile) {
            $bg = $enum('bg_type', array_filter(self::BACKGROUNDS));
            $out += [
                'heading_align' => $enum('heading_align', self::ALIGN),
                'button_align' => $enum('button_align', self::ALIGN),
                'heading_weight' => $enum('heading_weight', self::WEIGHTS),
                'line_height' => $num('line_height', 1, 3),
                'text_color' => $color('text_color'),
                'heading_color' => $color('heading_color'),
                'bg_type' => $bg,
                'bg_color' => in_array($bg, ['color', 'gradient'], true) ? $color('bg_color') : null,
                'bg_color2' => $bg === 'gradient' ? $color('bg_color2') : null,
                'bg_angle' => $bg === 'gradient' ? $num('bg_angle', 0, 360) : null,
                'overlay' => $bg === 'image' ? $num('overlay', 0, 90) : null,
                'bg_position' => $bg === 'image' ? $enum('bg_position', self::POSITIONS) : null,
                'width' => $enum('width', self::WIDTHS),
                'valign' => $enum('valign', self::VALIGN),
                'img_align' => $enum('img_align', self::ALIGN),
                'fit' => $enum('fit', self::FITS),
                'radius' => $num('radius', 0, 60),
                'shadow' => ! empty($s['shadow']) ? true : null,
                'btn_style' => $enum('btn_style', ['solid' => 1, 'outline' => 1]),
                'btn_color' => $color('btn_color'),
                'btn_radius' => $num('btn_radius', 0, 40),
                'btn_icon_pos' => $enum('btn_icon_pos', ['left' => 1, 'right' => 1]),
                'btn_full_mobile' => ! empty($s['btn_full_mobile']) ? true : null,
                'hide_mobile' => ! empty($s['hide_mobile']) ? true : null,
                'hide_desktop' => ! empty($s['hide_desktop']) ? true : null,
                'animation' => $enum('animation', array_filter(self::ANIMATIONS)),
            ];

            if ($bg === 'gradient' && $out['bg_color'] === null) {
                $out['bg_type'] = null;
            }
        }

        return array_filter($out, fn ($v) => $v !== null);
    }

    /** The CSS of one section, every rule under $scope (e.g. "#bx-3"). */
    public static function css(string $scope, mixed $style, int|string|null $tenantId): string
    {
        $st = self::clean($style);

        if ($st === []) {
            return '';
        }

        $s = $scope;
        $out = self::rules($st, $s, $tenantId);

        // Columns and the media position change the layout: from 768px up, unless the phone has its own.
        $wide = [];
        if (isset($st['columns'])) {
            $wide[] = "$s .grid,$s .cols{grid-template-columns:repeat({$st['columns']},minmax(0,1fr))}";
        }
        if (isset($st['media_pos'])) {
            $wide[] = in_array($st['media_pos'], ['left', 'right'], true)
                ? "$s .mx{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}$s .mx-m{order:".($st['media_pos'] === 'left' ? 0 : 2).'}'
                : "$s .mx{grid-template-columns:minmax(0,1fr)}$s .mx-m{order:".($st['media_pos'] === 'top' ? 0 : 2).'}';
        }
        if ($wide) {
            $out[] = '@media (min-width:768px){'.implode('', $wide).'}';
        }

        $m = self::rules($st['mobile'] ?? [], $s, $tenantId);
        if (isset($st['mobile']['columns'])) {
            $m[] = "$s .grid,$s .cols{grid-template-columns:repeat({$st['mobile']['columns']},minmax(0,1fr))}";
        }
        if (isset($st['mobile']['media_pos'])) {
            $m[] = "$s .mx-m{order:".($st['mobile']['media_pos'] === 'top' ? 0 : 2).'}';
        }
        if (! empty($st['btn_full_mobile'])) {
            $m[] = "$s .jbs{flex-direction:column;align-items:stretch}$s .jb{width:100%}";
        }
        if (! empty($st['hide_mobile'])) {
            $m[] = "$s{display:none}";
        }
        if ($m) {
            $out[] = '@media (max-width:767px){'.implode('', $m).'}';
        }

        if (! empty($st['hide_desktop'])) {
            $out[] = "@media (min-width:768px){{$s}{display:none}}";
        }

        return implode('', $out);
    }

    /** CSS class for the entrance animation, or ''. */
    public static function animation(mixed $style): string
    {
        $a = self::clean($style)['animation'] ?? null;

        return $a ? 'bx-anim bx-'.$a : '';
    }

    /** @return list<string> */
    private static function rules(array $st, string $s, int|string|null $tenantId): array
    {
        $r = [];
        $sec = "$s>section";
        $pxv = fn (float $v) => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');

        if (isset($st['align'])) {
            $r[] = "$sec,$s .wrap,$s .card,$s .formbox{text-align:{$st['align']}}$s .jbs,$s .chips,$s .stats,$s .logos{justify-content:".self::JUSTIFY[$st['align']].'}';
            $r[] = self::headingLine($s, $st['align']);
        }
        if (isset($st['heading_align'])) {
            $r[] = "$s h1,$s h2{text-align:{$st['heading_align']}}".self::headingLine($s, $st['heading_align']);
        }
        if (isset($st['button_align'])) {
            $r[] = "$s .jbs{justify-content:".self::JUSTIFY[$st['button_align']].'}';
        }

        $heading = isset($st['heading_px']) ? $pxv($st['heading_px']).'px' : (isset($st['heading_size']) ? self::HEADING_CSS[$st['heading_size']] : null);
        if ($heading) {
            $r[] = "$s h1,$s h2{font-size:$heading}";
        }
        $text = isset($st['text_px']) ? $pxv($st['text_px']).'px' : (isset($st['text_size']) ? self::TEXT_CSS[$st['text_size']] : null);
        if ($text) {
            $r[] = "$s p,$s li,$s blockquote,$s summary,$s td,$s th{font-size:$text}";
        }
        if (isset($st['heading_weight'])) {
            $r[] = "$s h1,$s h2,$s h3{font-weight:{$st['heading_weight']}}";
        }
        if (isset($st['line_height'])) {
            $r[] = "$sec{line-height:".$pxv($st['line_height']).'}';
        }
        if (isset($st['text_color'])) {
            $r[] = "$sec,$s .mute{color:{$st['text_color']}}";
        }
        if (isset($st['heading_color'])) {
            $r[] = "$s h1,$s h2{color:{$st['heading_color']}}";
        }

        $bg = match ($st['bg_type'] ?? null) {
            'color' => isset($st['bg_color']) ? $st['bg_color'] : null,
            'gradient' => 'linear-gradient('.(int) ($st['bg_angle'] ?? 135).'deg,'.$st['bg_color'].','.($st['bg_color2'] ?? '#ffffff').')',
            'image' => ($url = self::imageUrl($st['bg_image'] ?? null, $tenantId))
                ? 'linear-gradient(rgba(0,0,0,'.(($st['overlay'] ?? 40) / 100).'),rgba(0,0,0,'.(($st['overlay'] ?? 40) / 100).')),url("'.$url.'") '.($st['bg_position'] ?? 'center').'/cover no-repeat'
                : null,
            default => null,
        };
        if ($bg) {
            $r[] = "$sec{background:$bg}";
            if (($st['bg_type'] ?? null) === 'image' && ! isset($st['text_color'])) {
                $r[] = "$sec{color:#fff}$s h2{color:inherit}";
            }
        }

        if (isset($st['pad_top'])) {
            $r[] = "$sec:not(.hero),$s .hero>.wrap{padding-top:".$pxv($st['pad_top']).'px}';
        }
        if (isset($st['pad_bottom'])) {
            $r[] = "$sec:not(.hero),$s .hero>.wrap{padding-bottom:".$pxv($st['pad_bottom']).'px}';
        }
        if (isset($st['gap'])) {
            $r[] = "$s .grid,$s .gal,$s .jbs,$s .cols,$s .mx,$s .stats,$s .logos,$s .ic{gap:".$pxv($st['gap']).'px}';
        }
        if (isset($st['width'])) {
            $r[] = "$s .wrap{max-width:".self::WIDTH_CSS[$st['width']].'}';
        }
        if (isset($st['valign'])) {
            $r[] = "$s .mx,$s .grid,$s .cols{align-items:{$st['valign']}}";
        }

        $img = [];
        if (isset($st['img_width'])) {
            $img[] = 'width:'.$pxv($st['img_width']).'%';
        }
        if (isset($st['img_max_h'])) {
            $img[] = 'max-height:'.$pxv($st['img_max_h']).'px';
        }
        if (isset($st['ratio'])) {
            $img[] = $st['ratio'] === 'auto' ? 'aspect-ratio:auto' : 'aspect-ratio:'.str_replace(':', '/', $st['ratio']);
        }
        if (isset($st['fit'])) {
            $img[] = "object-fit:{$st['fit']}";
        }
        if (isset($st['radius'])) {
            $img[] = 'border-radius:'.$pxv($st['radius']).'px';
        }
        if (! empty($st['shadow'])) {
            $img[] = 'box-shadow:0 10px 30px rgba(17,24,39,.18)';
        }
        if (isset($st['img_align'])) {
            $img[] = match ($st['img_align']) {
                'left' => 'margin-left:0;margin-right:auto', 'right' => 'margin-left:auto;margin-right:0', default => 'margin-left:auto;margin-right:auto',
            };
        }
        if ($img) {
            $r[] = "$s img:not(.bg):not(.ic-img){".implode(';', $img).'}';
        }

        $btn = [];
        if (isset($st['btn_size'])) {
            [$pad, $font, $min] = self::BUTTON_CSS[$st['btn_size']];
            $btn[] = "padding:$pad;font-size:$font;min-height:$min";
        }
        if (($st['btn_style'] ?? null) === 'outline') {
            $btn[] = 'background:transparent;color:'.($st['btn_color'] ?? 'inherit').';border-color:currentColor';
        } elseif (isset($st['btn_color'])) {
            $btn[] = "background:{$st['btn_color']};border-color:{$st['btn_color']};color:#fff";
        }
        if (isset($st['btn_radius'])) {
            $btn[] = 'border-radius:'.$pxv($st['btn_radius']).'px';
        }
        if (($st['btn_icon_pos'] ?? null) === 'right') {
            $btn[] = 'flex-direction:row-reverse';
        }
        if ($btn) {
            $r[] = "$s .jb{".implode(';', $btn).'}';
        }

        return $r;
    }

    private static function headingLine(string $s, string $align): string
    {
        return "$s h2::after{".match ($align) {
            'center' => 'margin-left:auto;margin-right:auto', 'right' => 'margin-left:auto;margin-right:0', default => 'margin-left:0;margin-right:auto',
        }.'}';
    }

    /** An upload path as stored (a one-item list while still in the form). */
    private static function path(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Only this agency's own uploads, and only characters that cannot end the CSS string. */
    private static function imageUrl(?string $path, int|string|null $tenantId): ?string
    {
        $url = Embeds::image($path, $tenantId);

        return $url !== null && preg_match('#^[A-Za-z0-9/:._~%-]+$#', $url) ? $url : null;
    }
}
