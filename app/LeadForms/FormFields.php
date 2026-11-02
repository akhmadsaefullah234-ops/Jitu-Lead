<?php

namespace App\LeadForms;

use App\Regions\RegionDirectory;
use App\Shipping\Regions;
use App\Support\PhoneNumber;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * The fields of a form built in "Formulir": their types, the editor for them, the server-side rules for a
 * submission, and how answers become lead data. A field is stored as
 *
 *     {key, type, label, placeholder, required, options: [..], maps_to, source, value, parts: [..], required_parts: [..], mode}
 *
 * Visitors send them as f[key] (f[key][part] for an address, f[key][] for check boxes). An address in "region"
 * mode sends Kemendagri codes for provinsi, kota, kecamatan and kelurahan (picked from dropdowns that load from
 * /wilayah); they are checked to belong together and turned into names. Until the regions are imported it falls
 * back to typed parts.
 */
class FormFields
{
    public const TYPES = [
        'text' => 'Teks pendek',
        'textarea' => 'Teks panjang',
        'phone' => 'Nomor WhatsApp',
        'email' => 'Email',
        'number' => 'Angka',
        'select' => 'Pilihan dropdown',
        'radio' => 'Pilihan ganda (radio)',
        'checkbox' => 'Centang (checkbox)',
        'date' => 'Tanggal',
        'address' => 'Alamat lengkap',
        'photo' => 'Unggah foto',
        'hidden' => 'Kolom tersembunyi (UTM / sumber iklan)',
    ];

    public const WITH_OPTIONS = ['select', 'radio', 'checkbox'];

    /**
     * Where an answer goes on the lead. Answers not stored in one of the lead's own columns are listed under
     * "Jawaban formulir" on the lead; every answer is also written in the lead's activity.
     */
    public const MAPS = [
        '' => 'Hanya di "Jawaban formulir" lead',
        'name' => 'Nama lead',
        'phone' => 'Telepon / WhatsApp lead',
        'email' => 'Email lead',
        'variant' => 'Varian / pilihan produk',
        'quantity' => 'Jumlah',
        'shipping_address' => 'Alamat kirim',
        'payment_type' => 'Cara bayar (Transfer / COD)',
        'note' => 'Catatan',
        'custom' => 'Kolom tambahan lead',
    ];

    public const ADDRESS_PARTS = [
        'street' => 'Alamat (jalan, blok)',
        'rt_rw' => 'RT/RW',
        'landmark' => 'Nomor rumah / patokan',
        'kelurahan' => 'Kelurahan / desa',
        'kecamatan' => 'Kecamatan',
        'kota' => 'Kota / kabupaten',
        'provinsi' => 'Provinsi',
        'kode_pos' => 'Kode pos',
    ];

    public const ADDRESS_MODES = ['free' => 'Ketik bebas', 'region' => 'Dropdown wilayah (provinsi > kota > kecamatan > kelurahan)'];

    private const REGION_CODE = ['provinsi' => '/^\d{2}$/', 'kota' => '/^\d{2}\.\d{2}$/', 'kecamatan' => '/^\d{2}\.\d{2}\.\d{2}$/', 'kelurahan' => '/^\d{2}\.\d{2}\.\d{2}\.\d{4}$/'];

    /** Required when the address is required, unless the field picks its own (required_parts). */
    public const ADDRESS_REQUIRED = ['street', 'kecamatan', 'kota'];

    /** Which parts of a required address must be filled in. With dropdowns, a required level needs the ones above it. */
    public static function requiredParts(array $field): array
    {
        $parts = self::addressParts($field);
        $own = array_values(array_intersect($parts, (array) ($field['required_parts'] ?? [])));
        $required = $own !== [] ? $own : array_values(array_intersect($parts, self::ADDRESS_REQUIRED));

        if (self::regionMode($field)) {
            $chain = array_keys(RegionDirectory::CHAIN);
            $deepest = max(array_map(fn ($p) => (int) array_search($p, $chain, true), array_intersect($required, $chain)) ?: [-1]);
            $required = array_values(array_intersect(array_keys(self::ADDRESS_PARTS), array_unique([...$required, ...array_slice($chain, 0, $deepest + 1)])));
        }

        return $required;
    }

    /** Whether an address is drawn as region dropdowns: chosen so, and the regions are imported. */
    public static function regionMode(array $field): bool
    {
        return ($field['type'] ?? null) === 'address' && ($field['mode'] ?? null) === 'region' && RegionDirectory::available();
    }

    /** The parts an address asks for; with dropdowns, all of them. */
    public static function addressParts(array $field): array
    {
        return self::regionMode($field) ? array_keys(self::ADDRESS_PARTS) : (array) ($field['parts'] ?? array_keys(self::ADDRESS_PARTS));
    }

    public const HIDDEN_SOURCES = [
        'utm_source' => 'utm_source', 'utm_medium' => 'utm_medium', 'utm_campaign' => 'utm_campaign', 'utm_content' => 'utm_content', 'utm_term' => 'utm_term',
        'fbclid' => 'fbclid (klik iklan Meta)', 'ttclid' => 'ttclid (klik iklan TikTok)', 'gclid' => 'gclid (klik iklan Google)',
        'referrer' => 'Halaman asal (referrer)', 'page_url' => 'Alamat halaman formulir', 'query' => 'Parameter URL lain', 'static' => 'Nilai tetap',
    ];

    public const PHONE_PATTERN = '/^(?:0|62)8\d{7,12}$/';

    public const MAX_PHOTO_KB = 5120;

    /** The field list in the editor. */
    public static function repeater(): Repeater
    {
        $has = fn (array $types) => fn (Get $get) => in_array($get('type'), $types, true);

        return Repeater::make('fields')->label('Kolom formulir')->addActionLabel('Tambah kolom')
            ->collapsible()->collapsed()->cloneable()->reorderable()->reorderableWithButtons()->minItems(1)->maxItems(30)
            ->itemLabel(fn (array $state) => trim(($state['label'] ?? 'Kolom').' · '.(self::TYPES[$state['type'] ?? ''] ?? '').(! empty($state['required']) ? ' · wajib' : '')))
            ->rules([fn () => function (string $attribute, mixed $value, Closure $fail) {
                foreach (self::problems(is_array($value) ? array_values($value) : []) as $problem) {
                    $fail($problem);
                }
            }])
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    Select::make('type')->label('Jenis kolom')->options(self::TYPES)->default('text')->required()->native(false)->live()
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            $default = ['phone' => 'phone', 'email' => 'email', 'address' => 'shipping_address', 'number' => 'quantity'][$state] ?? null;
                            if ($default && blank($get('maps_to'))) {
                                $set('maps_to', $default);
                            }
                        }),
                    TextInput::make('label')->label('Label')->required()->maxLength(100)->live(onBlur: true)
                        ->afterStateUpdated(fn ($state, Set $set, Get $get) => blank($get('key')) ? $set('key', self::keyFrom((string) $state)) : null),
                    TextInput::make('placeholder')->label('Contoh isian (placeholder)')->maxLength(100)->visible(fn (Get $get) => ! in_array($get('type'), ['radio', 'checkbox', 'photo', 'hidden', 'address', 'date'], true)),
                    Toggle::make('required')->label('Wajib diisi')->default(false)->inline(false)->visible(fn (Get $get) => $get('type') !== 'hidden'),
                    TagsInput::make('options')->label('Pilihan')->placeholder('Ketik pilihan lalu Enter')->columnSpanFull()
                        ->visible($has(self::WITH_OPTIONS))->required(fn (Get $get) => in_array($get('type'), ['select', 'radio'], true))
                        ->helperText(fn (Get $get) => $get('type') === 'checkbox' ? 'Kosongkan untuk satu kotak centang, misalnya "Saya setuju dihubungi".' : null),
                    Select::make('mode')->label('Cara mengisi wilayah')->options(self::ADDRESS_MODES)->default('free')->native(false)->selectablePlaceholder(false)
                        ->columnSpanFull()->visible($has(['address']))->live()
                        ->helperText(fn (Get $get) => $get('mode') === 'region'
                            ? (RegionDirectory::available() ? 'Pembeli memilih provinsi, kota/kabupaten, kecamatan, lalu kelurahan/desa dari daftar resmi; kode pos terisi otomatis. Semua sub-isian ditampilkan.'
                                : 'Data wilayah belum diimpor (php artisan regions:import), jadi sementara tampil sebagai ketik bebas.')
                            : null),
                    Select::make('parts')->label('Sub-isian alamat')->multiple()->options(self::ADDRESS_PARTS)->default(array_keys(self::ADDRESS_PARTS))->columnSpanFull()
                        ->visible(fn (Get $get) => $get('type') === 'address' && $get('mode') !== 'region'),
                    Select::make('required_parts')->label('Sub-isian yang wajib (bila alamat wajib diisi)')->multiple()->options(self::ADDRESS_PARTS)
                        ->default(self::ADDRESS_REQUIRED)->columnSpanFull()->visible($has(['address']))
                        ->helperText('Kosongkan untuk memakai bawaan: alamat, kecamatan, dan kota/kabupaten.'),
                    Select::make('source')->label('Isi otomatis dari')->options(self::HIDDEN_SOURCES)->default('utm_source')->native(false)->live()->visible($has(['hidden'])),
                    TextInput::make('value')->label(fn (Get $get) => $get('source') === 'query' ? 'Nama parameter URL' : 'Nilai')->maxLength(100)
                        ->visible(fn (Get $get) => $get('type') === 'hidden' && in_array($get('source'), ['query', 'static'], true)),
                    Select::make('maps_to')->label('Simpan ke kolom lead')->options(self::MAPS)->default('')->native(false)->selectablePlaceholder(false),
                    TextInput::make('key')->label('Nama variabel')->required()->maxLength(40)->regex('/^[a-z][a-z0-9_]{0,39}$/')
                        ->helperText('Dipakai di pesan WhatsApp sebagai {nama_variabel}.')
                        ->validationMessages(['regex' => 'Huruf kecil, angka, dan garis bawah, diawali huruf.']),
                ]),
            ]);
    }

    public static function keyFrom(string $label): string
    {
        $key = Str::of($label)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(30, '')->value();

        return preg_match('/^[a-z]/', $key) ? $key : 'kolom'.($key !== '' ? '_'.$key : '');
    }

    /**
     * Gives every field a unique key and drops what does not belong to its type.
     *
     * @return list<array<string, mixed>>
     */
    public static function normalize(mixed $fields): array
    {
        $out = [];
        $used = [];

        foreach (is_array($fields) ? array_values($fields) : [] as $f) {
            if (! is_array($f) || ! isset(self::TYPES[$f['type'] ?? ''])) {
                continue;
            }

            $key = preg_match('/^[a-z][a-z0-9_]{0,39}$/', (string) ($f['key'] ?? '')) ? $f['key'] : self::keyFrom((string) ($f['label'] ?? 'kolom'));
            $base = $key;

            for ($i = 2; isset($used[$key]); $i++) {
                $key = $base.'_'.$i;
            }

            $used[$key] = true;
            $type = $f['type'];

            $out[] = array_filter([
                'key' => $key,
                'type' => $type,
                'label' => trim((string) ($f['label'] ?? '')) ?: self::TYPES[$type],
                'placeholder' => filled($f['placeholder'] ?? null) ? (string) $f['placeholder'] : null,
                'required' => $type !== 'hidden' && ! empty($f['required']),
                'options' => in_array($type, self::WITH_OPTIONS, true) ? array_values(array_filter(array_map(fn ($o) => trim((string) $o), (array) ($f['options'] ?? [])), 'strlen')) : null,
                'parts' => $type === 'address' ? array_values(array_intersect(array_keys(self::ADDRESS_PARTS), (array) ($f['parts'] ?? array_keys(self::ADDRESS_PARTS)))) ?: array_keys(self::ADDRESS_PARTS) : null,
                'required_parts' => $type === 'address' && filled($f['required_parts'] ?? null)
                    ? (array_values(array_intersect(array_keys(self::ADDRESS_PARTS), (array) $f['required_parts'])) ?: null) : null,
                'mode' => $type === 'address' && ($f['mode'] ?? null) === 'region' ? 'region' : null,
                'source' => $type === 'hidden' ? (isset(self::HIDDEN_SOURCES[$f['source'] ?? '']) ? $f['source'] : 'utm_source') : null,
                'value' => $type === 'hidden' && filled($f['value'] ?? null) ? (string) $f['value'] : null,
                'maps_to' => isset(self::MAPS[$f['maps_to'] ?? '']) && ($f['maps_to'] ?? '') !== '' ? $f['maps_to'] : null,
            ], fn ($v) => $v !== null);
        }

        return $out;
    }

    /**
     * What stops a form from making leads: no name, no way to contact. Shown in the editor.
     *
     * @return list<string>
     */
    public static function problems(array $fields): array
    {
        $maps = array_map(fn ($f) => is_array($f) ? ($f['maps_to'] ?? '') : '', $fields);
        $problems = [];

        if (! in_array('name', $maps, true)) {
            $problems[] = 'Formulir perlu satu kolom yang disimpan ke "Nama lead".';
        }

        if (! in_array('phone', $maps, true) && ! in_array('email', $maps, true)) {
            $problems[] = 'Formulir perlu kolom yang disimpan ke "Telepon / WhatsApp lead" atau "Email lead".';
        }

        foreach (['name', 'phone', 'email', 'quantity', 'payment_type'] as $single) {
            if (count(array_keys($maps, $single, true)) > 1) {
                $problems[] = 'Hanya satu kolom yang boleh disimpan ke "'.self::MAPS[$single].'".';
            }
        }

        $keys = array_filter(array_map(fn ($f) => is_array($f) ? ($f['key'] ?? null) : null, $fields));

        if (count($keys) !== count(array_unique($keys))) {
            $problems[] = 'Nama variabel tiap kolom harus berbeda.';
        }

        return $problems;
    }

    /**
     * Laravel rules for a submission, keyed by input name (f.key...).
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    public static function rules(array $fields): array
    {
        $rules = [];

        foreach ($fields as $f) {
            $name = 'f.'.$f['key'];
            $req = ! empty($f['required']) ? 'required' : 'nullable';
            $options = $f['options'] ?? [];

            switch ($f['type']) {
                case 'address':
                    $region = self::regionMode($f);
                    $rules[$name] = [$req, 'array'];
                    if ($region) {
                        $rules[$name][] = function (string $attribute, mixed $value, Closure $fail) {
                            if (is_array($value) && is_string($problem = RegionDirectory::resolve($value))) {
                                $fail($problem);
                            }
                        };
                    }
                    $mustFill = self::requiredParts($f);
                    foreach (self::addressParts($f) as $part) {
                        $partReq = $req === 'required' && in_array($part, $mustFill, true) ? 'required' : 'nullable';
                        $rules[$name.'.'.$part] = match (true) {
                            $region && isset(self::REGION_CODE[$part]) => [$partReq, 'string', 'regex:'.self::REGION_CODE[$part]],
                            $part === 'kode_pos' => [$partReq, 'regex:/^\d{5}$/'],
                            $part === 'provinsi' => [$partReq, 'string', 'max:60'],
                            $part === 'street' => [$partReq, 'string', 'max:250'],
                            default => [$partReq, 'string', 'max:100'],
                        };
                    }
                    break;
                case 'checkbox':
                    $rules[$name] = $options === [] ? [$req === 'required' ? 'accepted' : 'nullable'] : [$req, 'array', 'max:'.count($options)];
                    if ($options !== []) {
                        $rules[$name.'.*'] = ['string', 'in:'.self::inList($options)];
                    }
                    break;
                case 'photo':
                    $rules[$name] = [$req, 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_PHOTO_KB];
                    break;
                default:
                    $rules[$name] = [$req, ...match ($f['type']) {
                        'textarea' => ['string', 'max:2000'],
                        'phone' => ['string', 'max:30', function (string $a, mixed $v, Closure $fail) {
                            if (! self::validPhone($v)) {
                                $fail('Nomor WhatsApp harus diawali 08 atau 62, contoh 081234567890.');
                            }
                        }],
                        'email' => ['email:rfc', 'max:160'],
                        'number' => ['numeric', 'min:0', 'max:1000000000'],
                        'select', 'radio' => ['string', 'in:'.self::inList($options)],
                        'date' => ['date_format:Y-m-d'],
                        'hidden' => ['string', 'max:300'],
                        default => ['string', 'max:250'],
                    }];
            }
        }

        return $rules;
    }

    /** @return array<string, string> readable names for error messages */
    public static function attributes(array $fields): array
    {
        $out = [];

        foreach ($fields as $f) {
            $out['f.'.$f['key']] = mb_strtolower($f['label']);
            foreach ($f['type'] === 'address' ? self::addressParts($f) : [] as $part) {
                $out['f.'.$f['key'].'.'.$part] = mb_strtolower(self::ADDRESS_PARTS[$part]);
            }
        }

        return $out;
    }

    public static function validPhone(mixed $value): bool
    {
        $digits = preg_replace('/[\s().+-]+/', '', (string) $value);

        return is_string($value) && preg_match('/^\+?[\d\s().-]+$/', trim($value)) && preg_match(self::PHONE_PATTERN, (string) $digits);
    }

    /**
     * Turns a validated submission into: the lead's own columns, extra lead fields, the answers in order (for the
     * activity and the WhatsApp message) and the uploaded photos.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $input  the validated f[...] values
     * @return array{lead: array<string, mixed>, custom: array<string, string>, answers: list<array{key: string, label: string, value: string}>, vars: array<string, string>, photos: array<string, UploadedFile>}
     */
    public static function answers(array $fields, array $input): array
    {
        $lead = [];
        $custom = [];
        $answers = [];
        $vars = [];
        $photos = [];
        $variants = [];

        foreach ($fields as $f) {
            $raw = $input[$f['key']] ?? null;

            if ($f['type'] === 'photo') {
                if ($raw instanceof UploadedFile) {
                    $photos[$f['key']] = $raw;
                    $answers[] = ['key' => $f['key'], 'label' => $f['label'], 'value' => '(foto terlampir)'];
                }

                continue;
            }

            $parts = $f['type'] === 'address' ? self::addressValues($f, is_array($raw) ? $raw : []) : null;
            $value = $parts !== null ? self::address($parts, self::addressParts($f), $parts['kelurahan_type'] ?? 'Kel.') : self::display($f, $raw);

            if ($value === '') {
                continue;
            }

            $answers[] = ['key' => $f['key'], 'label' => $f['label'], 'value' => $value];
            $vars[$f['key']] = $value;

            if ($parts !== null && ($f['maps_to'] ?? null) === 'shipping_address') {
                $province = isset($parts['codes']['provinsi'])
                    ? RegionDirectory::shippingProvince($parts['provinsi'])
                    : Regions::province((string) ($parts['provinsi'] ?? '')) ?? Regions::provinceOfCity((string) ($parts['kota'] ?? ''));
                if ($province !== null) {
                    $lead['shipping_province'] = $province;
                }
                $lead['shipping_address_parts'] = array_filter(array_intersect_key($parts, self::ADDRESS_PARTS + ['codes' => 1]), fn ($v) => $v !== '' && $v !== []) ?: null;
            }

            match ($f['maps_to'] ?? null) {
                'name' => $lead['name'] = mb_substr($value, 0, 120),
                'phone' => $lead['phone'] = $value,
                'email' => $lead['email'] = mb_strtolower($value),
                'variant' => $variants[] = $value,
                'quantity' => $lead['quantity'] = max(1, min(100000, (int) $value)),
                'shipping_address' => $lead['shipping_address'] = mb_substr($value, 0, 500),
                'payment_type' => $lead['payment_type'] = self::paymentType($value),
                'note' => $lead['note'] = trim(($lead['note'] ?? '')."\n".$value),
                default => $custom[$f['label']] = mb_substr($value, 0, 500),
            };
        }

        if ($variants !== []) {
            $lead['variant'] = mb_substr(implode('; ', $variants), 0, 250);
        }

        // Common names people write in messages: {nama}, {telepon}, {email}, {alamat}, {jumlah}, {varian}.
        $vars += array_filter([
            'nama' => $lead['name'] ?? null, 'telepon' => $lead['phone'] ?? null, 'email' => $lead['email'] ?? null,
            'alamat' => $lead['shipping_address'] ?? null, 'jumlah' => isset($lead['quantity']) ? (string) $lead['quantity'] : null, 'varian' => $lead['variant'] ?? null,
        ]);

        return ['lead' => $lead, 'custom' => $custom, 'answers' => $answers, 'vars' => $vars, 'photos' => $photos];
    }

    /** "Transfer", "Transfer bank", "COD", "Bayar di tempat (COD)" => transfer | cod */
    public static function paymentType(string $answer): string
    {
        return preg_match('/\bcod\b|bayar di tempat|cash on delivery/i', $answer) ? 'cod' : 'transfer';
    }

    /** One answer as text. */
    public static function display(array $field, mixed $raw): string
    {
        return match ($field['type']) {
            'address' => self::address($v = self::addressValues($field, is_array($raw) ? $raw : []), self::addressParts($field), $v['kelurahan_type'] ?? 'Kel.'),
            'checkbox' => ($field['options'] ?? []) === []
                ? (filter_var($raw, FILTER_VALIDATE_BOOLEAN) || $raw === 'on' ? 'Ya' : '')
                : implode(', ', array_map('strval', array_filter((array) $raw, 'is_string'))),
            'date' => is_string($raw) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : '',
            'phone' => is_string($raw) ? ltrim((string) PhoneNumber::normalize($raw), '+') : '',
            default => is_scalar($raw) ? trim((string) $raw) : '',
        };
    }

    /**
     * The parts of an address answer as text, trimmed. With dropdowns the codes become names (kept under "codes"),
     * and an empty postal code takes the village's.
     *
     * @return array<string, mixed>
     */
    public static function addressValues(array $field, array $raw): array
    {
        $parts = [];
        foreach (self::addressParts($field) as $part) {
            $parts[$part] = is_scalar($raw[$part] ?? null) ? trim((string) $raw[$part]) : '';
        }

        if (! self::regionMode($field)) {
            return $parts;
        }

        $names = RegionDirectory::resolve($parts);
        $codes = [];

        foreach (array_keys(RegionDirectory::CHAIN) as $part) {
            if (is_array($names) && isset($names[$part])) {
                $codes[$part] = $parts[$part];
                $parts[$part] = $names[$part];
            } else {
                $parts[$part] = '';
            }
        }

        if ($parts['kode_pos'] === '' && is_array($names) && isset($names['postal'])) {
            $parts['kode_pos'] = $names['postal'];
        }

        return $parts + ['codes' => $codes, 'kelurahan_type' => is_array($names) ? ($names['kelurahan_type'] ?? 'Kel.') : 'Kel.'];
    }

    /** "Jl. Mawar 5, RT 01/RW 02, (dekat masjid), Kel. A, Kec. B, Kab. Bekasi, Jawa Barat 17520" */
    public static function address(array $parts, array $enabled, string $villagePrefix = 'Kel.'): string
    {
        $get = fn (string $k) => in_array($k, $enabled, true) ? trim((string) ($parts[$k] ?? '')) : '';
        $province = $get('provinsi');
        $province = (Regions::province($province) ?? $province);

        $pieces = array_filter([
            $get('street'),
            $get('rt_rw') !== '' ? 'RT/RW '.$get('rt_rw') : '',
            $get('landmark') !== '' ? '('.$get('landmark').')' : '',
            $get('kelurahan') !== '' ? $villagePrefix.' '.$get('kelurahan') : '',
            $get('kecamatan') !== '' ? 'Kec. '.$get('kecamatan') : '',
            $get('kota'),
            trim($province.' '.$get('kode_pos')),
        ], fn ($p) => $p !== '');

        return implode(', ', $pieces);
    }

    private static function inList(array $options): string
    {
        return implode(',', array_map(fn ($o) => '"'.str_replace('"', '""', $o).'"', $options));
    }
}
