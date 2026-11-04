<?php

namespace App\Welcome;

use App\Models\MediaFolder;
use App\Models\Property;
use App\Support\CurrentTenant;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;

/** The "Pesan sambutan WhatsApp" fields of a lead form, of a landing page that uses a form, and their preview. */
class WelcomeFields
{
    private const PREVIEW_STYLE = 'white-space:pre-line;padding:.6rem .8rem;border-radius:.5rem;background:rgba(37,211,102,.08);border:1px solid rgba(37,211,102,.25)';

    /** @return list<mixed> */
    public static function form(): array
    {
        $on = fn (Get $get) => (bool) $get('welcome.active');

        return [
            Toggle::make('welcome.active')->label('Kirim pesan sambutan WhatsApp otomatis')->live()
                ->helperText('JITU LEAD mengirim WhatsApp ke pembeli yang mengisi formulir ini, walaupun pembeli tidak lanjut ke chat.'),
            Grid::make(['default' => 1, 'sm' => 2])->visible($on)->schema([
                Select::make('welcome.delay')->label('Jeda kirim')->options(WelcomeSettings::DELAYS)->default(WelcomeSettings::DEFAULT_DELAY)->formatStateUsing(fn ($state) => $state ?? WelcomeSettings::DEFAULT_DELAY)->native(false)->selectablePlaceholder(false)
                    ->helperText('Bila dalam jeda ini pembeli sudah chat ke nomor toko, sambutan tidak dikirim.'),
                self::media('welcome.media_asset_id'),
                self::text('welcome.text', fn (Get $get) => self::fallback($get('property_id'))),
                self::preview('welcome.text', fn (Get $get) => self::fallback($get('property_id')), fn (Get $get) => $get('property_id')),
            ]),
            Text::make('Sekali per pembeli per formulir. Pembeli lama yang mengisi lagi hanya dikirimi bila tidak ada pesan keluar ke nomornya dalam 24 jam terakhir. Di luar jam layanan (21.00 sampai 05.00) sambutan menunggu sampai 05.00. Dikirim lewat nomor WhatsApp pemilik lead, dengan pesan perkenalan dulu bila nomor gateway itu belum pernah menyapa pembeli.')
                ->visible($on)->color('gray'),
        ];
    }

    /** @return list<mixed> */
    public static function page(): array
    {
        $custom = fn (Get $get) => $get('welcome.mode') === 'custom';

        return [
            Radio::make('welcome.mode')->label('Pesan sambutan WhatsApp untuk formulir di halaman ini')->options(WelcomeSettings::PAGE_MODES)->default('form')->formatStateUsing(fn ($state) => $state ?? 'form')->live()
                ->helperText('Mengikuti formulir: sambutan memakai pengaturan di menu Formulir (bila aktif di sana).'),
            Grid::make(['default' => 1, 'sm' => 2])->visible($custom)->schema([
                Select::make('welcome.delay')->label('Jeda kirim')->options(WelcomeSettings::DELAYS)->default(WelcomeSettings::DEFAULT_DELAY)->formatStateUsing(fn ($state) => $state ?? WelcomeSettings::DEFAULT_DELAY)->native(false)->selectablePlaceholder(false),
                self::media('welcome.media_asset_id'),
                self::text('welcome.text', fn (Get $get) => self::fallback($get('property_id'))),
                self::preview('welcome.text', fn (Get $get) => self::fallback($get('property_id')), fn (Get $get) => $get('property_id')),
            ]),
        ];
    }

    /** The text a lead gets when the form leaves it empty: the product's own, else the built-in one. */
    public static function fallback(mixed $productId): string
    {
        $product = filled($productId) ? Property::query()->find($productId) : null;

        return filled($product?->welcome_text) ? trim($product->welcome_text) : WelcomeSettings::builtIn(app(CurrentTenant::class)->get());
    }

    /** The welcome as a buyer would read it, with an example buyer and order. */
    public static function example(string $text, mixed $productId = null): string
    {
        $tenant = app(CurrentTenant::class)->get();
        $product = filled($productId) ? Property::query()->find($productId) : null;
        $name = $product?->name ?? ($tenant?->industry()->key === 'properti' ? 'Griya Asri Tipe 45' : 'Tas Badminton');
        $price = (int) ($product?->price_from ?: 349000);

        return strtr($text, [
            '{nama}' => 'Rina', '{agen}' => auth()->user()?->name ?? 'Andi', '{agensi}' => $tenant?->name ?? 'Toko Anda',
            '{produk}' => $name, '{properti}' => $name,
            '{ringkasan}' => $name.' (Hitam) x2, dikirim ke Coblong, Kota Bandung',
            '{total}' => WelcomeText::rupiah($price * 2).' + ongkir Rp18.000 (JNE REG) = '.WelcomeText::rupiah($price * 2 + 18000),
        ]);
    }

    private static function text(string $name, \Closure $fallback): Textarea
    {
        return Textarea::make($name)->label('Teks sambutan')->rows(5)->maxLength(1000)->columnSpanFull()->live(debounce: 500)
            ->placeholder($fallback)
            ->helperText('Kosongkan untuk memakai teks sambutan produk (halaman produk, tab Iklan & promo), atau teks bawaan. Variabel: '.WelcomeSettings::VARIABLES.'. {total} = harga promo x jumlah, ditambah ongkir bila bisa dihitung dari alamat.');
    }

    private static function preview(string $name, \Closure $fallback, \Closure $product): Text
    {
        return Text::make(fn (Get $get) => 'Contoh yang diterima pembeli: '."\n".self::example(filled($get($name)) ? (string) $get($name) : $fallback($get), $product($get)))
            ->extraAttributes(['style' => self::PREVIEW_STYLE])->columnSpanFull();
    }

    private static function media(string $name): Select
    {
        return Select::make($name)->label('Lampiran dari pustaka media (opsional)')->searchable()->options(fn () => MediaFolder::groupedOptions())
            ->helperText('Misalnya foto produk. Dikirim tepat setelah teks sambutan.');
    }
}
