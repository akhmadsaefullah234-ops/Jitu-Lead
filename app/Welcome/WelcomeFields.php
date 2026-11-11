<?php

namespace App\Welcome;

use App\LeadForms\FormFields;
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
use Illuminate\Support\HtmlString;

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
                self::text('welcome.text', fn (Get $get) => self::fallback($get('property_id')), fn (Get $get) => self::formKeys($get('fields'))),
                self::preview('welcome.text', fn (Get $get) => self::fallback($get('property_id')), fn (Get $get) => $get('property_id'), fn (Get $get) => self::formKeys($get('fields'))),
            ]),
            Text::make('Sekali per pembeli per formulir. Pembeli lama yang mengisi lagi hanya dikirimi bila tidak ada pesan keluar ke nomornya dalam 24 jam terakhir. Di luar jam layanan (21.00 sampai 05.00) sambutan menunggu sampai 05.00. Dikirim lewat nomor WhatsApp pemilik lead, tanpa pesan perkenalan nomor: sambutan inilah perkenalannya.')
                ->visible($on)->color('gray'),
        ];
    }

    /** @return list<mixed> */
    public static function page(): array
    {
        $custom = fn (Get $get) => $get('welcome.mode') === 'custom';
        // A page may hold several forms: any {jawaban:key} is accepted there.
        $keys = fn () => null;

        return [
            Radio::make('welcome.mode')->label('Pesan sambutan WhatsApp untuk formulir di halaman ini')->options(WelcomeSettings::PAGE_MODES)->default('form')->formatStateUsing(fn ($state) => $state ?? 'form')->live()
                ->helperText('Mengikuti formulir: sambutan memakai pengaturan di menu Formulir (bila aktif di sana).'),
            Grid::make(['default' => 1, 'sm' => 2])->visible($custom)->schema([
                Select::make('welcome.delay')->label('Jeda kirim')->options(WelcomeSettings::DELAYS)->default(WelcomeSettings::DEFAULT_DELAY)->formatStateUsing(fn ($state) => $state ?? WelcomeSettings::DEFAULT_DELAY)->native(false)->selectablePlaceholder(false),
                self::media('welcome.media_asset_id'),
                self::text('welcome.text', fn (Get $get) => self::fallback($get('property_id')), $keys),
                self::preview('welcome.text', fn (Get $get) => self::fallback($get('property_id')), fn (Get $get) => $get('property_id'), $keys),
            ]),
        ];
    }

    /**
     * The variables as chips that put themselves at the cursor, the form's own answers as {jawaban:key}, and a
     * warning while the text uses a variable a welcome cannot fill. Also used for a product's welcome text.
     *
     * @param  \Closure|null  $keys  the form's field keys, or null when the text is not tied to one form
     */
    public static function helpers(Textarea $field, string $name, ?\Closure $keys = null): Textarea
    {
        // Read from the field itself: inside belowContent a relative path would resolve under the field's own state.
        $known = fn () => $keys ? $keys(new Get($field)) : null;
        $unknown = fn () => WelcomeText::unknown((string) $field->getState(), $known());

        return $field->belowContent([
            Text::make(fn () => self::chips((array) $known())),
            Text::make(fn () => 'Variabel tidak dikenal: '.implode(', ', array_map(fn ($v) => '{'.$v.'}', $unknown()))
                .'. Tidak akan terkirim apa adanya: baris "Label: {variabel}" dihapus, selain itu diganti "-".')
                ->color('danger')->visible(fn () => $unknown() !== []),
        ]);
    }

    /** @param  list<string>  $fieldKeys */
    private static function chips(array $fieldKeys): HtmlString
    {
        $vars = [...array_map(fn ($k, $label) => [$k, $label], array_keys(WelcomeText::VARIABLES), WelcomeText::VARIABLES),
            ...array_map(fn ($k) => [WelcomeText::ANSWER_PREFIX.$k, 'jawaban field '.$k], $fieldKeys)];

        $insert = "let t = \$el.closest('.fi-fo-field').querySelector('textarea'); if (! t) return; t.focus(); "
            ."t.setRangeText(\$el.dataset.v, t.selectionStart, t.selectionEnd, 'end'); t.dispatchEvent(new Event('input', { bubbles: true }));";

        return new HtmlString('<div style="display:flex;flex-wrap:wrap;gap:.3rem;margin-top:.25rem">'.implode('', array_map(fn ($v) => '<button type="button" data-v="'.e('{'.$v[0].'}').'" title="'.e($v[1]).'" x-on:click="'.e($insert).'"'
            .' style="font:500 .75rem/1.4 ui-monospace,monospace;padding:.1rem .45rem;border-radius:999px;border:1px solid rgba(37,211,102,.4);background:rgba(37,211,102,.08);cursor:pointer">'
            .e('{'.$v[0].'}').'</button>', $vars)).'</div>');
    }

    /** @return list<string> the keys of a form's fields (not photos) */
    public static function formKeys(mixed $fields): array
    {
        return collect(is_array($fields) ? FormFields::normalize($fields) : [])->filter(fn ($f) => is_array($f) && filled($f['key'] ?? null) && ($f['type'] ?? '') !== 'photo')
            ->pluck('key')->map(fn ($k) => (string) $k)->values()->all();
    }

    /** The text a lead gets when the form leaves it empty: the product's own, else the built-in one. */
    public static function fallback(mixed $productId): string
    {
        $product = filled($productId) ? Property::query()->find($productId) : null;

        return filled($product?->welcome_text) ? trim($product->welcome_text) : WelcomeSettings::builtIn(app(CurrentTenant::class)->get());
    }

    /**
     * The welcome as a buyer would read it, with an example buyer and order (filled the way a real one is).
     *
     * @param  list<string>  $fieldKeys
     */
    public static function example(string $text, mixed $productId = null, array $fieldKeys = []): string
    {
        $tenant = app(CurrentTenant::class)->get();
        $product = filled($productId) ? Property::query()->find($productId) : null;
        $name = $product?->name ?? ($tenant?->industry()->key === 'properti' ? 'Griya Asri Tipe 45' : 'Tas Badminton');
        $price = (int) ($product?->price_from ?: 349000);

        return WelcomeText::fill($text, [
            'nama' => 'Rina', 'agen' => auth()->user()?->name ?? 'Andi', 'agensi' => $tenant?->name ?? 'Toko Anda',
            'produk' => $name, 'properti' => $name, 'daftar_produk' => '1. '.$name,
            'ringkasan' => $name.' (Hitam) x2, dikirim ke Coblong, Kota Bandung',
            'total' => WelcomeText::rupiah($price * 2).' + ongkir Rp18.000 (JNE REG) = '.WelcomeText::rupiah($price * 2 + 18000),
            'whatsapp' => '08990001234', 'telepon' => '08990001234', 'email' => 'rina@contoh.com',
            'alamat' => 'Jl. Mawar No. 5 RT 001/002, Kel. Sekeloa, Kec. Coblong, Kota Bandung, Jawa Barat 40134',
            'varian' => 'Hitam', 'warna' => 'Hitam', 'jumlah' => '2', 'ongkir' => 'Rp18.000', 'kurir' => 'JNE REG',
        ] + collect($fieldKeys)->mapWithKeys(fn ($k) => [WelcomeText::ANSWER_PREFIX.mb_strtolower($k) => '(jawaban '.$k.')'])->all());
    }

    private static function text(string $name, \Closure $fallback, \Closure $keys): Textarea
    {
        return self::helpers(Textarea::make($name)->label('Teks sambutan')->rows(5)->maxLength(1000)->columnSpanFull()->live(debounce: 500)
            ->placeholder($fallback)
            ->helperText('Kosongkan untuk memakai teks sambutan produk (halaman produk, tab Iklan & promo), atau teks bawaan. Klik variabel di bawah untuk menyisipkannya. {total} = harga promo x jumlah, ditambah ongkir bila bisa dihitung dari alamat. Jawaban yang kosong tidak terkirim: baris "Label: {variabel}" dihapus.'),
            $name, $keys);
    }

    private static function preview(string $name, \Closure $fallback, \Closure $product, \Closure $keys): Text
    {
        return Text::make(fn (Get $get) => 'Contoh yang diterima pembeli (data contoh): '."\n".self::example(filled($get($name)) ? (string) $get($name) : $fallback($get), $product($get), (array) $keys($get)))
            ->extraAttributes(['style' => self::PREVIEW_STYLE])->columnSpanFull();
    }

    private static function media(string $name): Select
    {
        return Select::make($name)->label('Lampiran dari pustaka media (opsional)')->searchable()->options(fn () => MediaFolder::groupedOptions())
            ->helperText('Misalnya foto produk. Dikirim tepat setelah teks sambutan.');
    }
}
