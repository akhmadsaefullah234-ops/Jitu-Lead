<?php

namespace App\LeadForms;

use App\LandingPages\Buttons;
use App\Leads\AddressCheck;
use App\Regions\RegionDirectory;
use App\Shipping\Regions;
use App\Support\PhoneNumber;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
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
 *     {key, type, label, placeholder, required, options: [..], payment_map: {choice: cod|transfer|other}, maps_to, source, value,
 *      parts: [..], required_parts: [..], part_labels, part_placeholders, rules: [..], mode}
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
        'product' => 'Produk yang diminati (dicocokkan ke katalog)',
        'quantity' => 'Jumlah',
        'shipping_address' => 'Alamat kirim',
        'payment_type' => 'Cara bayar (COD / transfer / lainnya)',
        'note' => 'Catatan',
        'custom' => 'Kolom tambahan lead',
    ];

    /** Sub-fields of an address, in their default order, with their default labels. */
    public const ADDRESS_PARTS = [
        'street' => 'Alamat (jalan, blok)',
        'house' => 'Nomor rumah',
        'landmark' => 'Nomor rumah / patokan',
        'rt_rw' => 'RT/RW',
        'dusun' => 'Dusun / kampung',
        'kelurahan' => 'Kelurahan / desa',
        'kecamatan' => 'Kecamatan',
        'kota' => 'Kota / kabupaten',
        'provinsi' => 'Provinsi',
        'kode_pos' => 'Kode pos',
    ];

    /** The sub-fields addresses had before "Nomor rumah" and "Dusun" were added: what an older field without a list shows. */
    public const LEGACY_PARTS = ['street', 'rt_rw', 'landmark', 'kelurahan', 'kecamatan', 'kota', 'provinsi', 'kode_pos'];

    private const PART_PLACEHOLDERS = [
        'street' => 'Jl. Mawar, Blok C', 'house' => 'No. 12', 'landmark' => 'Dekat masjid, pagar hijau', 'rt_rw' => '001/002',
        'dusun' => 'Dusun Krajan', 'kode_pos' => '12345',
    ];

    /**
     * "Salah satu wajib" rules of an address: name => [the sub-fields, the editor's label, what is said when all are
     * empty]. A rule in CONDITIONAL_RULES only holds when the address has no RT/RW (empty, 0, 000 or 000/000).
     */
    public const ADDRESS_RULES = [
        'rt_or_dusun' => [['rt_rw', 'dusun'], 'RT/RW atau dusun/kampung (salah satu wajib)', 'Isi RT/RW atau dusun/kampung.'],
        'house_or_landmark' => [['house', 'landmark'], 'Nomor rumah atau patokan (salah satu wajib)', 'Isi nomor rumah atau patokan.'],
        'landmark_if_no_rt' => [['house', 'landmark'], 'Nomor rumah atau patokan wajib bila RT/RW kosong atau 0/000', AddressCheck::MESSAGE],
    ];

    /** Rules that only hold when the address has no RT/RW (see App\Leads\AddressCheck). */
    public const CONDITIONAL_RULES = ['landmark_if_no_rt'];

    /** The rules a shop's order form starts with: RT/RW or dusun, and without RT/RW a house number or landmark. */
    public const RETAIL_RULES = ['rt_or_dusun', 'landmark_if_no_rt'];

    public const ADDRESS_MODES = ['free' => 'Ketik bebas', 'region' => 'Dropdown wilayah (provinsi > kota > kecamatan > kelurahan)'];

    /**
     * Extra choices of an address field (stored as address_options). The first two only work with region dropdowns:
     * - hide_postal: the postal code is not asked; the server takes the picked kelurahan's. It is shown as a fallback
     *   when the kelurahan has no postal code or was not picked from the list.
     * - city_first: the dropdowns start at the city/regency (one list of all of them, labelled with the province);
     *   the province is derived from the city's code (12.71 => 12).
     * - rt_choice: "Alamat saya memakai: RT/RW | Dusun/kampung" shows the RT/RW or the dusun box, not both.
     * - soft_rt_check: the street box (one box for "jalan, RT/RW dan/atau dusun, nomor rumah/patokan") should name an
     *   RT/RW or a dusun; without one the lead is marked "alamat belum lengkap" and the AI asks, nothing is refused.
     */
    public const ADDRESS_OPTIONS = [
        'hide_postal' => 'Sembunyikan kode pos (otomatis dari kelurahan)',
        'city_first' => 'Mulai dari kota/kabupaten (provinsi otomatis)',
        'rt_choice' => 'Pilihan "Alamat saya memakai: RT/RW | Dusun/kampung"',
        'soft_rt_check' => 'Periksa RT/RW atau dusun di kolom jalan (tidak menolak kiriman)',
    ];

    /** Shown (and kept on the lead) when the street box of a soft_rt_check address names no RT/RW or dusun. */
    public const SOFT_RT_MESSAGE = 'Tambahkan RT/RW atau dusun';

    public const SOFT_RT_HINT = 'Contoh: Jl. Mawar No. 10, RT 01/RW 02';

    /** Kept on a lead (custom_fields) whose address still needs something; the AI asks the buyer for it. */
    public const INCOMPLETE_KEY = 'alamat_belum_lengkap';

    /** A lead saved at step 1 of a two-step form, before the address. */
    public const STEP_ONE_MESSAGE = 'Alamat belum diisi (pembeli berhenti di langkah 1 formulir)';

    /** The answer of the rt_choice question. */
    public const RT_KINDS = ['rt' => 'RT/RW', 'dusun' => 'Dusun/kampung'];

    private const REGION_CODE = ['provinsi' => '/^\d{2}$/', 'kota' => '/^\d{2}\.\d{2}$/', 'kecamatan' => '/^\d{2}\.\d{2}\.\d{2}$/', 'kelurahan' => '/^\d{2}\.\d{2}\.\d{2}\.\d{4}$/'];

    /** What the old editor filled in as required sub-fields; new fields start with it too. */
    public const ADDRESS_REQUIRED = ['street', 'kecamatan', 'kota'];

    /** How a payment answer is paid: what decides the stage automations and the order summary. */
    public const PAYMENT_KINDS = ['transfer' => 'Transfer / bayar di muka', 'cod' => 'COD (bayar di tempat)', 'other' => 'Lainnya (QRIS, e-wallet, dll.)'];

    /**
     * Which sub-fields must be filled in: none when the address itself is optional, otherwise exactly the ones ticked.
     * With dropdowns, a required level makes the levels above it required (they are needed to pick it).
     */
    public static function requiredParts(array $field): array
    {
        if (empty($field['required'])) {
            return [];
        }

        $parts = self::addressParts($field);
        $required = array_values(array_intersect($parts, self::requiredTicks($field)));

        if (self::regionMode($field)) {
            $chain = self::regionChain($field);
            $deepest = max(array_map(fn ($p) => (int) array_search($p, $chain, true), array_intersect($required, $chain)) ?: [-1]);
            $required = array_values(array_intersect($parts, array_unique([...$required, ...array_slice($chain, 0, $deepest + 1)])));
        }

        // A hidden postal code comes from the kelurahan; its fallback box is only required when that has none.
        if (self::postalHidden($field)) {
            $required = array_values(array_diff($required, ['kode_pos']));
        }

        return $required;
    }

    /**
     * The sub-fields ticked as required. Forms saved before the list could be emptied only stored it when something
     * was ticked, so a field without the list keeps the old default; an empty list means none.
     */
    private static function requiredTicks(array $field): array
    {
        return array_key_exists('required_parts', $field) ? (array) $field['required_parts'] : self::ADDRESS_REQUIRED;
    }

    /** Whether an address is drawn as region dropdowns: chosen so, and the regions are imported. */
    public static function regionMode(array $field): bool
    {
        return ($field['type'] ?? null) === 'address' && ($field['mode'] ?? null) === 'region' && RegionDirectory::available();
    }

    /**
     * The sub-fields an address shows, in the owner's order. With dropdowns, the region levels shown must run from
     * the province down without a gap (province > city > district, say, but never a district without its city): a
     * level below a gap is left out, and the levels keep their top-down order.
     */
    public static function addressParts(array $field): array
    {
        $parts = (array) ($field['parts'] ?? self::LEGACY_PARTS);

        if (! self::regionMode($field)) {
            return $parts;
        }

        $chain = self::regionChain($field);
        $parts = array_values(array_filter($parts, fn ($p) => ! isset(RegionDirectory::CHAIN[$p]) || in_array($p, $chain, true)));
        $slots = array_keys(array_filter($parts, fn ($p) => isset(RegionDirectory::CHAIN[$p])));

        foreach ($slots as $i => $slot) {
            $parts[$slot] = $chain[$i];
        }

        return $parts;
    }

    /**
     * With dropdowns: the region levels shown, from the province (or, with city_first, the city) down to the first
     * level that is hidden.
     */
    public static function regionChain(array $field): array
    {
        $parts = (array) ($field['parts'] ?? self::LEGACY_PARTS);
        $chain = [];
        $levels = array_keys(RegionDirectory::CHAIN);

        if (self::addressOption($field, 'city_first')) {
            $levels = array_slice($levels, 1);
        }

        foreach ($levels as $level) {
            if (! in_array($level, $parts, true)) {
                break;
            }
            $chain[] = $level;
        }

        return $chain;
    }

    public static function addressOption(array $field, string $option): bool
    {
        return ($field['type'] ?? null) === 'address' && in_array($option, (array) ($field['address_options'] ?? []), true);
    }

    /** Dropdowns that start at the city/regency; the province comes from the city's code. */
    public static function cityFirst(array $field): bool
    {
        return self::regionMode($field) && self::addressOption($field, 'city_first') && in_array('kota', self::regionChain($field), true);
    }

    /** The postal code is taken from the picked kelurahan and only shown as a fallback. */
    public static function postalHidden(array $field): bool
    {
        return self::regionMode($field) && self::addressOption($field, 'hide_postal') && in_array('kelurahan', self::regionChain($field), true)
            && in_array('kode_pos', (array) ($field['parts'] ?? self::LEGACY_PARTS), true);
    }

    /** "Alamat saya memakai: RT/RW | Dusun/kampung": both boxes shown, one asked at a time. */
    public static function rtChoice(array $field): bool
    {
        $parts = self::addressParts($field);

        return self::addressOption($field, 'rt_choice') && in_array('rt_rw', $parts, true) && in_array('dusun', $parts, true);
    }

    /** The street box checked for an RT/RW or dusun (soft_rt_check), when shown. */
    public static function softRtCheck(array $field): bool
    {
        return self::addressOption($field, 'soft_rt_check') && in_array('street', self::addressParts($field), true);
    }

    /**
     * The sub-fields an answer holds: the ones shown, plus with dropdowns the province of a city_first address and
     * the postal code of a kelurahan (filled in by the server even when the box is hidden).
     */
    public static function storedParts(array $field): array
    {
        $parts = self::addressParts($field);

        if (self::regionMode($field)) {
            if (self::cityFirst($field) && ! in_array('provinsi', $parts, true)) {
                $parts[] = 'provinsi';
            }
            if (in_array('kelurahan', self::regionChain($field), true) && ! in_array('kode_pos', $parts, true)) {
                $parts[] = 'kode_pos';
            }
        }

        return $parts;
    }

    /**
     * The region codes of an answer for RegionDirectory::resolve(): the levels shown, and with city_first the
     * province taken from the city's code ("32.16" => "32").
     *
     * @return array<string, mixed>
     */
    public static function regionCodes(array $field, array $value): array
    {
        $codes = array_intersect_key($value, array_flip(self::regionChain($field)));

        if (self::cityFirst($field) && is_string($codes['kota'] ?? null) && preg_match(self::REGION_CODE['kota'], trim($codes['kota']))) {
            $codes = ['provinsi' => substr(trim($codes['kota']), 0, 2)] + $codes;
        }

        return $codes;
    }

    /** The label of a sub-field: the owner's, else the default ("Patokan" once "Nomor rumah" has its own box). */
    public static function partLabel(array $field, string $part): string
    {
        $own = trim((string) ($field['part_labels'][$part] ?? ''));

        if ($own !== '') {
            return $own;
        }

        return $part === 'landmark' && in_array('house', (array) ($field['parts'] ?? []), true) ? 'Patokan' : self::ADDRESS_PARTS[$part];
    }

    public static function partPlaceholder(array $field, string $part): string
    {
        return trim((string) ($field['part_placeholders'][$part] ?? '')) ?: (self::PART_PLACEHOLDERS[$part] ?? '');
    }

    /**
     * The "salah satu wajib" rules in force: only on a required address, and only over the sub-fields it shows.
     * "landmark_if_no_rt" gives way to "house_or_landmark", which asks the same always.
     *
     * @return array<string, array{0: list<string>, 1: string, 2: bool}> rule => [shown sub-fields, message, only without RT/RW]
     */
    public static function addressRules(array $field): array
    {
        if (empty($field['required'])) {
            return [];
        }

        $shown = self::addressParts($field);
        $out = [];

        $rules = (array) ($field['rules'] ?? []);

        foreach ($rules as $rule) {
            $pair = array_values(array_intersect(self::ADDRESS_RULES[$rule][0] ?? [], $shown));
            $conditional = in_array($rule, self::CONDITIONAL_RULES, true);

            if ($pair !== [] && ! ($rule === 'landmark_if_no_rt' && in_array('house_or_landmark', $rules, true))) {
                $out[$rule] = [$pair, self::ADDRESS_RULES[$rule][2], $conditional];
            }
        }

        return $out;
    }

    /**
     * The editor's list of sub-fields (shown ones first, in order, then the hidden ones) for a stored field.
     *
     * @return list<array{part: string, show: bool, required: bool, label: string, placeholder: string}>
     */
    public static function subfieldState(array $field): array
    {
        $parts = array_values(array_intersect((array) ($field['parts'] ?? self::LEGACY_PARTS), array_keys(self::ADDRESS_PARTS)));
        $required = self::requiredTicks($field);
        $rows = [];

        foreach ([...$parts, ...array_diff(array_keys(self::ADDRESS_PARTS), $parts)] as $part) {
            $rows[] = [
                'part' => $part,
                'show' => in_array($part, $parts, true),
                'required' => in_array($part, $required, true),
                'label' => (string) ($field['part_labels'][$part] ?? ''),
                'placeholder' => (string) ($field['part_placeholders'][$part] ?? ''),
            ];
        }

        return $rows;
    }

    /** Stored fields as the editor shows them: address sub-fields and payment choices as their own lists. */
    public static function forEditor(mixed $fields): array
    {
        return array_map(function (array $f) {
            if ($f['type'] === 'address') {
                $f['subfields'] = self::subfieldState($f);
            }

            if (($f['maps_to'] ?? null) === 'payment_type' && in_array($f['type'], ['select', 'radio'], true)) {
                $f['payment_choices'] = array_map(fn (string $o) => ['label' => $o, 'kind' => self::paymentKind($f, $o) ?? 'other'], (array) ($f['options'] ?? []));
            }

            return $f;
        }, self::normalize($fields));
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
                        ->visible(fn (Get $get) => in_array($get('type'), self::WITH_OPTIONS, true) && ! self::editsPayment($get))
                        ->required(fn (Get $get) => in_array($get('type'), ['select', 'radio'], true) && ! self::editsPayment($get))
                        ->helperText(fn (Get $get) => $get('type') === 'checkbox' ? 'Kosongkan untuk satu kotak centang, misalnya "Saya setuju dihubungi".' : null),
                    Repeater::make('payment_choices')->label('Pilihan cara bayar')->columnSpanFull()->visible(fn (Get $get) => self::editsPayment($get))
                        ->addActionLabel('Tambah pilihan')->reorderable()->reorderableWithButtons()->minItems(1)->maxItems(10)->defaultItems(0)
                        ->helperText('Tulis pilihan sesuai yang Anda layani (mis. hanya "COD"), lalu tentukan jenisnya. COD langsung dikemas; transfer dan lainnya menunggu pembayaran.')
                        ->table([
                            Repeater\TableColumn::make('Pilihan'),
                            Repeater\TableColumn::make('Jenis'),
                        ])
                        ->schema([
                            TextInput::make('label')->label('Pilihan')->required()->maxLength(60)->placeholder('Transfer bank'),
                            Select::make('kind')->label('Jenis')->options(self::PAYMENT_KINDS)->default('transfer')->required()->native(false)->selectablePlaceholder(false),
                        ]),
                    Select::make('mode')->label('Cara mengisi wilayah')->options(self::ADDRESS_MODES)->default('free')->native(false)->selectablePlaceholder(false)
                        ->columnSpanFull()->visible($has(['address']))->live()
                        ->helperText(fn (Get $get) => $get('mode') === 'region'
                            ? (RegionDirectory::available() ? 'Pembeli memilih dari daftar resmi, dari provinsi ke bawah. Tingkat wilayah boleh berhenti di mana saja (mis. sampai kecamatan), tapi tidak boleh melompat.'
                                : 'Data wilayah belum diimpor (php artisan regions:import), jadi sementara tampil sebagai ketik bebas.')
                            : null),
                    Repeater::make('subfields')->label('Sub-isian alamat')->columnSpanFull()->visible($has(['address']))
                        ->addable(false)->deletable(false)->reorderable()->reorderableWithButtons()->collapsible()->collapsed()
                        ->default(fn () => self::subfieldState(['parts' => array_keys(self::ADDRESS_PARTS)]))
                        ->helperText('Seret untuk mengurutkan. Sub-isian yang disembunyikan tidak tampil di formulir. Tanda wajib berlaku bila kolom alamat "Wajib diisi".')
                        ->itemLabel(fn (array $state) => (trim((string) ($state['label'] ?? '')) ?: (self::ADDRESS_PARTS[$state['part'] ?? ''] ?? 'Sub-isian'))
                            .(empty($state['show']) ? ' · disembunyikan' : (! empty($state['required']) ? ' · wajib' : '')))
                        ->schema([
                            Hidden::make('part'),
                            Grid::make(2)->schema([
                                Toggle::make('show')->label('Tampil')->default(true)->inline(false)->live(),
                                Toggle::make('required')->label('Wajib')->default(false)->inline(false)->visible(fn (Get $get) => (bool) $get('show')),
                                TextInput::make('label')->label('Label')->maxLength(60)->placeholder(fn (Get $get) => self::ADDRESS_PARTS[$get('part')] ?? '')
                                    ->visible(fn (Get $get) => (bool) $get('show')),
                                TextInput::make('placeholder')->label('Contoh isian')->maxLength(80)->placeholder(fn (Get $get) => self::PART_PLACEHOLDERS[$get('part')] ?? '')
                                    ->visible(fn (Get $get) => (bool) $get('show')),
                            ]),
                        ]),
                    CheckboxList::make('address_options')->label('Pilihan alamat')->columnSpanFull()->visible($has(['address']))
                        ->options(self::ADDRESS_OPTIONS)
                        ->helperText('Kode pos tersembunyi dan "mulai dari kota/kabupaten" hanya berlaku untuk dropdown wilayah. Kode pos tetap tampil sebagai cadangan bila kelurahan tidak punya kode pos. Pemeriksaan RT/RW di kolom jalan tidak menolak kiriman: lead ditandai "alamat belum lengkap" dan AI menanyakannya.'),
                    CheckboxList::make('rules')->label('Aturan "salah satu wajib" (bila alamat wajib diisi)')->columnSpanFull()->visible($has(['address']))
                        ->options(array_map(fn ($r) => $r[1], self::ADDRESS_RULES))
                        ->helperText('Untuk alamat dusun tanpa RT/RW, pilih "bila RT/RW kosong atau 0/000": alamat dengan RT/RW tetap boleh tanpa nomor rumah/patokan.'),
                    Select::make('source')->label('Isi otomatis dari')->options(self::HIDDEN_SOURCES)->default('utm_source')->native(false)->live()->visible($has(['hidden'])),
                    TextInput::make('value')->label(fn (Get $get) => $get('source') === 'query' ? 'Nama parameter URL' : 'Nilai')->maxLength(100)
                        ->visible(fn (Get $get) => $get('type') === 'hidden' && in_array($get('source'), ['query', 'static'], true)),
                    Select::make('maps_to')->label('Simpan ke kolom lead')->options(self::MAPS)->default('')->native(false)->selectablePlaceholder(false)->live()
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            if ($state === 'payment_type' && blank($get('payment_choices'))) {
                                $set('payment_choices', array_map(fn ($o) => ['label' => $o, 'kind' => self::guessPayment((string) $o) ?? 'other'],
                                    array_values(array_filter((array) $get('options'))) ?: ['Transfer', 'COD (bayar di tempat)']));
                            }
                        }),
                    TextInput::make('key')->label('Nama variabel')->required()->maxLength(40)->regex('/^[a-z][a-z0-9_]{0,39}$/')
                        ->helperText('Dipakai di pesan WhatsApp sebagai {nama_variabel}.')
                        ->validationMessages(['regex' => 'Huruf kecil, angka, dan garis bawah, diawali huruf.']),
                ]),
            ]);
    }

    /** In the editor: a choice field that is saved as the lead's way of paying. */
    private static function editsPayment(Get $get): bool
    {
        return $get('maps_to') === 'payment_type' && in_array($get('type'), ['select', 'radio'], true);
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
                'options' => in_array($type, self::WITH_OPTIONS, true) ? self::optionsOf($f) : null,
                'payment_map' => self::paymentMapOf($f),
                ...($type === 'address' ? self::addressSettings($f) : []),
                'mode' => $type === 'address' && ($f['mode'] ?? null) === 'region' ? 'region' : null,
                'source' => $type === 'hidden' ? (isset(self::HIDDEN_SOURCES[$f['source'] ?? '']) ? $f['source'] : 'utm_source') : null,
                'value' => $type === 'hidden' && filled($f['value'] ?? null) ? (string) $f['value'] : null,
                'maps_to' => isset(self::MAPS[$f['maps_to'] ?? '']) && ($f['maps_to'] ?? '') !== '' ? $f['maps_to'] : null,
            ], fn ($v) => $v !== null);
        }

        return $out;
    }

    /** @return list<string> the choices of a choice field (from the payment list when it has one) */
    private static function optionsOf(array $f): array
    {
        $raw = self::isPaymentField($f) && filled($f['payment_choices'] ?? null)
            ? array_map(fn ($c) => is_array($c) ? ($c['label'] ?? '') : '', (array) $f['payment_choices'])
            : (array) ($f['options'] ?? []);

        return array_values(array_unique(array_filter(array_map(fn ($o) => trim((string) $o), $raw), 'strlen')));
    }

    private static function isPaymentField(array $f): bool
    {
        return ($f['maps_to'] ?? null) === 'payment_type' && in_array($f['type'] ?? null, ['select', 'radio'], true);
    }

    /** @return array<string, string>|null choice => cod|transfer|other, for a payment field */
    private static function paymentMapOf(array $f): ?array
    {
        if (! self::isPaymentField($f)) {
            return null;
        }

        $given = [];
        foreach ((array) ($f['payment_choices'] ?? []) as $c) {
            if (is_array($c) && filled($c['label'] ?? null)) {
                $given[trim((string) $c['label'])] = (string) ($c['kind'] ?? '');
            }
        }
        $given += (array) ($f['payment_map'] ?? []);

        $map = [];
        foreach (self::optionsOf($f) as $o) {
            $kind = $given[$o] ?? null;
            $map[$o] = isset(self::PAYMENT_KINDS[$kind]) ? $kind : (self::guessPayment($o) ?? 'other');
        }

        return $map ?: null;
    }

    /**
     * An address's sub-field settings from the editor's list (subfields) or as stored: the shown sub-fields in order,
     * the required ones (empty = none), own labels and placeholders, and the "salah satu wajib" rules.
     *
     * @return array<string, mixed>
     */
    private static function addressSettings(array $f): array
    {
        $known = array_keys(self::ADDRESS_PARTS);
        $labels = [];
        $placeholders = [];

        if (is_array($f['subfields'] ?? null) && $f['subfields'] !== []) {
            $parts = [];
            $required = [];
            foreach ($f['subfields'] as $row) {
                $part = is_array($row) ? ($row['part'] ?? null) : null;
                if (! in_array($part, $known, true) || in_array($part, $parts, true) || empty($row['show'])) {
                    continue;
                }
                $parts[] = $part;
                if (! empty($row['required'])) {
                    $required[] = $part;
                }
                $labels[$part] = trim((string) ($row['label'] ?? ''));
                $placeholders[$part] = trim((string) ($row['placeholder'] ?? ''));
            }
        } else {
            $parts = array_values(array_unique(array_intersect((array) ($f['parts'] ?? self::LEGACY_PARTS), $known)));
            $required = self::requiredTicks($f);
            $labels = (array) ($f['part_labels'] ?? []);
            $placeholders = (array) ($f['part_placeholders'] ?? []);
        }

        $parts = $parts ?: self::LEGACY_PARTS;
        $options = array_values(array_intersect(array_keys(self::ADDRESS_OPTIONS), (array) ($f['address_options'] ?? [])));

        // A hidden postal code still has its box: the fallback shown when the kelurahan has none.
        if (in_array('hide_postal', $options, true) && ! in_array('kode_pos', $parts, true)) {
            $parts[] = 'kode_pos';
        }

        $clean = fn (array $texts) => array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 80), array_intersect_key($texts, array_flip($parts))), 'strlen') ?: null;

        return [
            'parts' => $parts,
            'required_parts' => array_values(array_intersect($parts, $required)),
            'part_labels' => $clean($labels),
            'part_placeholders' => $clean($placeholders),
            'rules' => array_values(array_intersect(array_keys(self::ADDRESS_RULES), (array) ($f['rules'] ?? []))) ?: null,
            'address_options' => $options ?: null,
        ];
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

        foreach ($fields as $f) {
            if (! is_array($f) || ($f['type'] ?? null) !== 'address' || ($f['mode'] ?? null) !== 'region') {
                continue;
            }
            $settings = self::addressSettings($f);
            $levels = array_keys(RegionDirectory::CHAIN);
            $cityFirst = in_array('city_first', (array) $settings['address_options'], true);
            // With "mulai dari kota/kabupaten" the province is not shown: it comes from the city.
            $levels = $cityFirst ? array_slice($levels, 1) : $levels;
            $shown = array_values(array_intersect($levels, $settings['parts']));
            if ($shown !== array_slice($levels, 0, count($shown))) {
                $problems[] = 'Alamat "'.($f['label'] ?? 'Alamat').'": tingkat wilayah tidak boleh melompat. Tampilkan dari '.($cityFirst ? 'kota/kabupaten' : 'provinsi').' ke bawah tanpa celah (mis. kecamatan perlu kota/kabupaten).';
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
                        $rules[$name][] = function (string $attribute, mixed $value, Closure $fail) use ($f) {
                            if (! is_array($value)) {
                                return;
                            }
                            $names = RegionDirectory::resolve(self::regionCodes($f, $value));
                            if (is_string($names)) {
                                // With city_first the province is ours, taken from the city: a bad one means a bad city.
                                $fail(self::cityFirst($f) && str_contains($names, 'provinsi') ? 'Pilihan kota/kabupaten tidak dikenal. Pilih dari daftar.' : $names);

                                return;
                            }
                            // A hidden postal code comes from the kelurahan; without one the fallback box must be filled.
                            $typed = is_scalar($value['kode_pos'] ?? null) ? trim((string) $value['kode_pos']) : '';
                            $any = array_filter($value, fn ($v) => is_scalar($v) && trim((string) $v) !== '');
                            if (self::postalHidden($f) && ! isset($names['postal']) && $typed === '' && (! empty($f['required']) || $any !== [])) {
                                $fail('Isi kode pos (5 angka): kelurahan/desa yang dipilih belum punya kode pos.');
                            }
                        };
                    }
                    if (self::rtChoice($f)) {
                        $rules[$name.'.rt_kind'] = ['nullable', 'in:'.implode(',', array_keys(self::RT_KINDS))];
                        $rules[$name][] = function (string $attribute, mixed $value, Closure $fail) use ($f) {
                            // Dusun/kampung: the courier needs a house number or a landmark (as "landmark_if_no_rt").
                            if (! is_array($value) || ($value['rt_kind'] ?? null) !== 'dusun' || isset(self::addressRules($f)['landmark_if_no_rt'])) {
                                return;
                            }
                            $pair = array_intersect(['house', 'landmark'], self::addressParts($f));
                            if ($pair !== [] && ! array_filter($pair, fn ($p) => is_scalar($value[$p] ?? null) && trim((string) $value[$p]) !== '')) {
                                $fail(AddressCheck::MESSAGE);
                            }
                        };
                    }
                    if ($oneOf = self::addressRules($f)) {
                        $rules[$name][] = function (string $attribute, mixed $value, Closure $fail) use ($oneOf) {
                            foreach ($oneOf as [$pair, $message, $withoutRt]) {
                                // "landmark_if_no_rt": only an address without a real RT/RW (empty, 0, 000/000).
                                if ($withoutRt && is_array($value) && AddressCheck::hasRtRw($value['rt_rw'] ?? null)) {
                                    continue;
                                }
                                if (! array_filter($pair, fn ($p) => is_array($value) && is_scalar($value[$p] ?? null) && trim((string) $value[$p]) !== '')) {
                                    $fail($message);
                                }
                            }
                        };
                        // An address left out entirely still has to meet its rules.
                        $rules[$name][0] = 'required';
                    }
                    $mustFill = self::requiredParts($f);
                    $choice = self::rtChoice($f);
                    foreach (self::addressParts($f) as $part) {
                        $partReq = $req === 'required' && in_array($part, $mustFill, true) ? 'required' : 'nullable';
                        // With the RT/RW | dusun choice only the chosen box is asked (prepare() empties the other).
                        if ($choice && $partReq === 'required' && in_array($part, ['rt_rw', 'dusun'], true)) {
                            $partReq = 'required_if:'.$name.'.rt_kind,'.($part === 'rt_rw' ? 'rt' : 'dusun');
                        }
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
                        // A quantity is a whole number of at least 1.
                        'number' => ($f['maps_to'] ?? null) === 'quantity' ? ['integer', 'min:1', 'max:100000'] : ['numeric', 'min:0', 'max:1000000000'],
                        'select', 'radio' => ['string', 'in:'.self::inList($options)],
                        'date' => ['date_format:Y-m-d'],
                        'hidden' => ['string', 'max:300'],
                        default => ['string', 'max:250'],
                    }];
            }
        }

        return $rules;
    }

    /**
     * The visitor's f[...] input made ready for validation: with the RT/RW | dusun choice, the box not chosen is
     * emptied, and an RT/RW of only zeros ("0", "00", "000/000") counts as none.
     *
     * @param  list<array<string, mixed>>  $fields
     */
    public static function prepare(array $fields, array $input): array
    {
        foreach ($fields as $f) {
            if ($f['type'] !== 'address' || ! self::rtChoice($f) || ! is_array($input[$f['key']] ?? null)) {
                continue;
            }

            $value = $input[$f['key']];
            $kind = ($value['rt_kind'] ?? null) === 'dusun' ? 'dusun' : 'rt';
            $value['rt_kind'] = $kind;

            if ($kind === 'dusun' || ! AddressCheck::hasRtRw($value['rt_rw'] ?? null)) {
                $value['rt_rw'] = '';
            }
            if ($kind === 'rt') {
                $value['dusun'] = '';
            }

            $input[$f['key']] = $value;
        }

        return $input;
    }

    /**
     * Where a two-step form splits: the position of the first address field, when something the buyer fills in
     * comes before it. Step 1 is everything before it (and the hidden ad-source fields), step 2 the rest.
     *
     * @param  list<array<string, mixed>>  $fields
     */
    public static function stepSplit(array $fields): ?int
    {
        foreach (array_values($fields) as $i => $f) {
            if ($f['type'] === 'address') {
                $before = array_filter(array_slice($fields, 0, $i), fn ($b) => $b['type'] !== 'hidden');

                return $before !== [] ? $i : null;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> the fields of step 1 (with every hidden field) */
    public static function stepOneFields(array $fields): array
    {
        $split = self::stepSplit($fields);

        if ($split === null) {
            return $fields;
        }

        return array_values(array_filter($fields, fn ($f, $i) => $i < $split || $f['type'] === 'hidden', ARRAY_FILTER_USE_BOTH));
    }

    /** @return array<string, string> readable names for error messages */
    public static function attributes(array $fields): array
    {
        $out = [];

        foreach ($fields as $f) {
            $out['f.'.$f['key']] = mb_strtolower($f['label']);
            foreach ($f['type'] === 'address' ? self::addressParts($f) : [] as $part) {
                $out['f.'.$f['key'].'.'.$part] = mb_strtolower(self::partLabel($f, $part));
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
        $incomplete = [];

        foreach ($fields as $f) {
            $raw = $input[$f['key']] ?? null;

            if ($f['type'] === 'photo') {
                if ($raw instanceof UploadedFile) {
                    $photos[$f['key']] = $raw;
                    $answers[] = ['key' => $f['key'], 'label' => $f['label'], 'value' => '(foto terlampir)', 'hidden' => false];
                }

                continue;
            }

            $parts = $f['type'] === 'address' ? self::addressValues($f, is_array($raw) ? $raw : []) : null;
            $value = $parts !== null ? self::address($parts, self::storedParts($f), $parts['kelurahan_type'] ?? 'Kel.') : self::display($f, $raw);

            if ($value === '') {
                continue;
            }

            // "Tambahkan RT/RW atau dusun": the lead is marked, the submission is kept.
            if ($parts !== null && ($f['maps_to'] ?? null) === 'shipping_address' && self::softRtCheck($f) && self::lacksRtOrDusun($parts)) {
                $incomplete[] = self::SOFT_RT_MESSAGE;
            }

            $answers[] = ['key' => $f['key'], 'label' => $f['label'], 'value' => $value, 'hidden' => $f['type'] === 'hidden'];
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
                'product' => $lead['product'] = mb_substr($value, 0, 200),
                'quantity' => $lead['quantity'] = max(1, min(100000, (int) $value)),
                'shipping_address' => $lead['shipping_address'] = mb_substr($value, 0, 500),
                'payment_type' => $lead['payment_type'] = self::paymentKind($f, $value),
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

        // {ringkasan}: every answer the visitor filled in, one per line (not the hidden ad-source fields).
        $summary = implode("\n", array_map(fn ($a) => $a['label'].': '.$a['value'], array_filter($answers, fn ($a) => ! $a['hidden'] && $a['value'] !== '(foto terlampir)')));
        if ($summary !== '') {
            $vars['ringkasan'] = $summary;
        }

        return ['lead' => $lead, 'custom' => $custom, 'answers' => array_map(fn ($a) => array_diff_key($a, ['hidden' => 1]), $answers), 'vars' => $vars, 'photos' => $photos,
            'incomplete' => array_values(array_unique($incomplete))];
    }

    /**
     * Whether an address (parts of a form answer) names neither an RT/RW nor a dusun/kampung, in its own boxes or
     * written in the street box ("RT 01", "RT/RW 02/03", "Dusun X", "Dsn.", "Kp. Y").
     */
    public static function lacksRtOrDusun(array $parts): bool
    {
        $street = is_scalar($parts['street'] ?? null) ? (string) $parts['street'] : '';

        return ! AddressCheck::hasRtRw($parts['rt_rw'] ?? null) && trim((string) ($parts['dusun'] ?? '')) === ''
            && ! AddressCheck::textHasRtRw($street) && ! AddressCheck::textHasDusun($street);
    }

    /**
     * A message written around the answers ("Pembayaran: {pembayaran}"): the variables filled in, and a line whose
     * variables are all empty (a field left blank, or no longer on the form) left out, so a summary only shows
     * what the form asked and the visitor answered.
     */
    public static function fillSummary(string $text, array $vars): string
    {
        $lines = [];

        foreach (preg_split('/\r\n|\n|\r/', $text) as $line) {
            if (preg_match_all('/\{([a-z0-9_]+)\}/i', $line, $m) && ! array_filter($m[1], fn ($k) => trim((string) ($vars[Str::lower($k)] ?? '')) !== '')) {
                continue;
            }
            $lines[] = Buttons::fill($line, $vars);
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /**
     * How a payment answer is paid, from the owner's mapping of the field's choices; an answer the mapping does not
     * know is guessed from its words, and "other" when nothing fits (never assumed to be a transfer).
     */
    public static function paymentKind(array $field, string $answer): string
    {
        $kind = $field['payment_map'][$answer] ?? null;

        return isset(self::PAYMENT_KINDS[$kind]) ? $kind : (self::guessPayment($answer) ?? 'other');
    }

    /** "COD", "Bayar di tempat" => cod; "Transfer bank", "BCA" => transfer; anything else (QRIS, OVO) => null. */
    public static function guessPayment(string $answer): ?string
    {
        return match (true) {
            (bool) preg_match('/\bcod\b|bayar di tempat|cash on delivery/i', $answer) => 'cod',
            (bool) preg_match('/transfer|\bbank\b|\bbca\b|\bbri\b|\bbni\b|mandiri|rekening|\btf\b/i', $answer) => 'transfer',
            default => null,
        };
    }

    /** One answer as text. */
    public static function display(array $field, mixed $raw): string
    {
        return match ($field['type']) {
            'address' => self::address($v = self::addressValues($field, is_array($raw) ? $raw : []), self::storedParts($field), $v['kelurahan_type'] ?? 'Kel.'),
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
     * a city_first address gets the province of its city, and the postal code is the village's: always when the
     * box is hidden or not shown at all, otherwise when it was left empty.
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

        $chain = self::regionChain($field);
        $picked = self::regionCodes($field, $parts);
        $names = RegionDirectory::resolve($picked);
        $codes = [];

        foreach (self::cityFirst($field) ? ['provinsi', ...$chain] : $chain as $part) {
            if (is_array($names) && isset($names[$part])) {
                $codes[$part] = $picked[$part];
                $parts[$part] = $names[$part];
            } else {
                $parts[$part] = '';
            }
        }

        if (in_array('kelurahan', $chain, true)) {
            $parts['kode_pos'] ??= '';
            $village = is_array($names) ? ($names['postal'] ?? null) : null;

            if ($village !== null && ($parts['kode_pos'] === '' || self::postalHidden($field))) {
                $parts['kode_pos'] = $village;
            }
        }

        return $parts + ['codes' => $codes, 'kelurahan_type' => is_array($names) ? ($names['kelurahan_type'] ?? 'Kel.') : 'Kel.'];
    }

    /**
     * "Jl. Mawar, No. 5, RT/RW 01/02, Dusun Krajan, (dekat masjid), Kel. A, Kec. B, Kab. Bekasi, Jawa Barat 17520":
     * only the sub-fields shown and filled in.
     */
    public static function address(array $parts, array $enabled, string $villagePrefix = 'Kel.'): string
    {
        $get = fn (string $k) => in_array($k, $enabled, true) ? trim((string) ($parts[$k] ?? '')) : '';
        $province = $get('provinsi');
        $province = (Regions::province($province) ?? $province);

        $pieces = array_filter([
            $get('street'),
            $get('house') !== '' ? (preg_match('/^\d/', $get('house')) ? 'No. '.$get('house') : $get('house')) : '',
            $get('rt_rw') !== '' ? 'RT/RW '.$get('rt_rw') : '',
            $get('dusun') !== '' ? (preg_match('/^(dusun|dsn\.?|kampung|kp\.?)\s/i', $get('dusun')) ? $get('dusun') : 'Dusun '.$get('dusun')) : '',
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
