<?php

namespace App\Regions;

use App\Models\Region;
use App\Shipping\Regions;
use Illuminate\Support\Facades\Cache;

/**
 * Looks things up in the imported regions table: the children of a region for the address dropdowns, whether a
 * chain of picked codes belongs together, and the official city behind what a buyer typed ("Jaksel", "Kab Bdg").
 * Every answer is cached until the next import. When nothing is imported yet, available() is false and callers
 * keep their old behaviour (typed addresses, the built-in province list).
 */
class RegionDirectory
{
    /** Address parts of a dropdown address, top to bottom, with their region level. */
    public const CHAIN = ['provinsi' => Region::PROVINCE, 'kota' => Region::CITY, 'kecamatan' => Region::DISTRICT, 'kelurahan' => Region::VILLAGE];

    /** Short names buyers write for cities and regencies => the official name without "Kota"/"Kabupaten". */
    public const CITY_ALIASES = [
        'jaksel' => 'jakarta selatan', 'jakbar' => 'jakarta barat', 'jaktim' => 'jakarta timur', 'jakut' => 'jakarta utara', 'jakpus' => 'jakarta pusat',
        'tangsel' => 'tangerang selatan', 'jogja' => 'yogyakarta', 'jogjakarta' => 'yogyakarta', 'yogya' => 'yogyakarta', 'solo' => 'surakarta',
        'bdg' => 'bandung', 'sby' => 'surabaya', 'smg' => 'semarang', 'mks' => 'makassar', 'plg' => 'palembang', 'pku' => 'pekanbaru',
        'bpn' => 'balikpapan', 'bjm' => 'banjarmasin', 'mdn' => 'medan', 'dps' => 'denpasar', 'bks' => 'bekasi', 'tgr' => 'tangerang',
    ];

    private const PREFIX = '/^(?:provinsi|prov\.?|kabupaten\s+administrasi|kota\s+administrasi|kabupaten|kab\.?|kota|kecamatan|kec\.?|kelurahan|kel\.?|desa|ds\.?)\s+/u';

    public static function version(): string
    {
        return (string) Cache::get('regions.version', '0');
    }

    /** Called after an import: every cached answer is dropped. */
    public static function flush(): void
    {
        Cache::forever('regions.version', (string) microtime(true));
    }

    public static function available(): bool
    {
        return Cache::remember('regions.available.'.self::version(), 600, fn () => Region::query()->exists());
    }

    /**
     * Postal codes of the villages whose name starts with $name, then of the villages in districts named so ("kedungwaringin"),
     * for looking a place up by name at a courier that only searches by postal code.
     *
     * @return list<string>
     */
    public static function postalCodesFor(string $name, int $limit = 8): array
    {
        $key = self::searchKey($name);

        if (mb_strlen($key) < 3) {
            return [];
        }

        return Cache::remember('regions.postal.'.self::version().'.'.sha1($key), 3600, function () use ($key, $limit) {
            $like = addcslashes($key, '%_\\').'%';
            $villages = Region::query()->where('level', Region::VILLAGE)->where('search', 'like', $like)->whereNotNull('postal_code')->orderByRaw('search = ? desc', [$key])->limit(50)->pluck('postal_code');

            $districts = Region::query()->where('level', Region::DISTRICT)->where('search', 'like', $like)->orderByRaw('search = ? desc', [$key])->limit(5)->pluck('code');
            $inDistricts = $districts->isEmpty() ? collect() : Region::query()->where('level', Region::VILLAGE)->whereIn('parent_code', $districts)->whereNotNull('postal_code')->pluck('postal_code');

            return $villages->merge($inDistricts)->filter(fn ($c) => preg_match('/^\d{5}$/', (string) $c))->unique()->take($limit)->values()->all();
        });
    }

    /** "Kabupaten Bandung Barat" => "bandung barat": how names are compared and searched. */
    public static function searchKey(?string $name): string
    {
        $key = trim(preg_replace('/\s+/u', ' ', mb_strtolower((string) $name)));

        return trim(preg_replace(self::PREFIX, '', $key));
    }

    /**
     * The regions directly under a code (the provinces when null), sorted by name: [code, name]; for villages
     * [code, name, postal code]; for provinces [code, name, '', other names people type, e.g. "dki jakarta jabar"].
     *
     * @return list<array{0: string, 1: string, 2?: string, 3?: string}>
     */
    public static function children(?string $parent): array
    {
        return Cache::rememberForever('regions.children.'.self::version().'.'.($parent ?? 'root'), function () use ($parent) {
            return Region::query()
                ->when($parent === null, fn ($q) => $q->where('level', Region::PROVINCE), fn ($q) => $q->where('parent_code', $parent))
                ->orderBy('name')->get(['code', 'name', 'postal_code'])
                ->map(fn (Region $r) => match (true) {
                    $parent === null => [$r->code, $r->name, '', self::provinceAliases($r->name)],
                    (bool) $r->postal_code => [$r->code, $r->name, $r->postal_code],
                    default => [$r->code, $r->name],
                })
                ->all();
        });
    }

    /**
     * Every city and regency in one list, for address dropdowns that start at the city ("mulai dari
     * kota/kabupaten"): [code, "Kabupaten Bandung (Jawa Barat)", '', other words people type]. The province in the
     * label keeps twins apart (Kota/Kabupaten Bandung, Kabupaten Bandung Barat); the extra words let "kab bekasi",
     * "kota band" or "jaksel" find their city. Sorted by name, cached until the next import.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function allCities(): array
    {
        return Cache::rememberForever('regions.all-cities.'.self::version(), function () {
            $provinces = Region::query()->where('level', Region::PROVINCE)->pluck('name', 'code');
            $aliases = [];
            foreach (self::CITY_ALIASES as $short => $official) {
                $aliases[$official][] = $short;
            }

            return Region::query()->where('level', Region::CITY)->orderBy('name')->get(['code', 'name', 'search'])
                ->map(function (Region $c) use ($provinces, $aliases) {
                    $province = self::shippingProvince((string) ($provinces[substr($c->code, 0, 2)] ?? ''));
                    $type = preg_match('/^kota\b/iu', $c->name) ? 'kota' : 'kab kabupaten';

                    return [$c->code, $c->name.($province !== '' ? ' ('.$province.')' : ''), '',
                        mb_strtolower(trim($type.' '.implode(' ', $aliases[(string) $c->search] ?? []).' '.self::provinceAliases((string) ($provinces[substr($c->code, 0, 2)] ?? ''))))];
                })
                ->all();
        });
    }

    /**
     * The names behind picked codes, checked from the top: every code must exist, be of its level and sit under the
     * one above it, and a lower part needs the parts above. Returns the names (and the village's postal code), or an
     * error message.
     *
     * @param  array<string, mixed>  $codes  provinsi, kota, kecamatan, kelurahan
     * @return array{provinsi?: string, kota?: string, kecamatan?: string, kelurahan?: string, kelurahan_type?: string, postal?: string}|string
     */
    public static function resolve(array $codes): array|string
    {
        $picked = [];
        foreach (self::CHAIN as $part => $level) {
            $code = trim((string) ($codes[$part] ?? ''));
            if ($code !== '') {
                $picked[$part] = $code;
            }
        }

        if ($picked === []) {
            return [];
        }

        $rows = Region::query()->whereIn('code', array_values($picked))->get(['code', 'parent_code', 'level', 'name', 'postal_code'])->keyBy('code');
        $labels = ['provinsi' => 'provinsi', 'kota' => 'kota/kabupaten', 'kecamatan' => 'kecamatan', 'kelurahan' => 'kelurahan/desa'];
        $out = [];
        $above = null;

        foreach (self::CHAIN as $part => $level) {
            if (! isset($picked[$part])) {
                // A gap is only allowed at the bottom of the chain.
                foreach (array_slice(array_keys(self::CHAIN), $level) as $lower) {
                    if (isset($picked[$lower])) {
                        return 'Pilih '.$labels[$part].' dulu sebelum '.$labels[$lower].'.';
                    }
                }
                break;
            }

            $row = $rows->get($picked[$part]);

            if ($row === null || (int) $row->level !== $level) {
                return 'Pilihan '.$labels[$part].' tidak dikenal. Pilih dari daftar.';
            }

            if ($above !== null && $row->parent_code !== $above) {
                return ucfirst($labels[$part]).' tidak berada di '.$labels[array_keys(self::CHAIN)[$level - 2]].' yang dipilih.';
            }

            $out[$part] = $row->name;
            $above = $row->code;

            if ($level === Region::VILLAGE) {
                // Kemendagri numbers kelurahan from 1001 and desa from 2001.
                $out['kelurahan_type'] = str_starts_with(substr($row->code, -4), '1') ? 'Kel.' : 'Desa';
                if ($row->postal_code) {
                    $out['postal'] = $row->postal_code;
                }
            }
        }

        return $out;
    }

    /** Short and common names of a province, for searching ("DKI Jakarta jakarta dki" for DKI). */
    private static function provinceAliases(string $official): string
    {
        $name = self::shippingProvince($official);

        return mb_strtolower(trim($name.' '.implode(' ', Regions::PROVINCES[$name] ?? [])));
    }

    /** "Daerah Khusus Ibukota Jakarta" => "DKI Jakarta": the province name shipping zones and automations use. */
    public static function shippingProvince(string $official): string
    {
        return Regions::province($official) ?? $official;
    }

    /**
     * The official city or regency behind what someone typed, and its province (as shipping uses it). The name is
     * null when the text fits more than one (e.g. "Bandung": the city and the regency), the whole answer null when
     * nothing or several provinces fit. A district name ("Cibinong") also finds its city.
     *
     * @return array{name: ?string, province: string}|null
     */
    public static function matchCity(?string $text, ?string $province = null, ?string $district = null): ?array
    {
        $raw = trim(preg_replace('/\s+/u', ' ', mb_strtolower((string) $text)));

        if ($raw === '' || ! self::available()) {
            return null;
        }

        $key = self::searchKey($raw);
        $key = self::CITY_ALIASES[$key] ?? $key;

        return Cache::remember('regions.city.'.self::version().'.'.md5($raw.'|'.$province.'|'.$district), 3600, function () use ($raw, $key, $province, $district) {
            $provinceCodes = null;
            if (filled($province) && ($wanted = Regions::province($province) ?? null)) {
                $provinceCodes = Region::query()->where('level', Region::PROVINCE)->get(['code', 'name'])
                    ->filter(fn (Region $p) => self::shippingProvince($p->name) === $wanted)->pluck('code')->all();
            }

            $cities = Region::query()->where('level', Region::CITY)->where('search', $key)
                ->when($provinceCodes !== null, fn ($q) => $q->whereIn('parent_code', $provinceCodes))->get(['code', 'name', 'parent_code']);

            if ($cities->isEmpty()) {
                // Perhaps a district was typed as the city.
                $parents = Region::query()->where('level', Region::DISTRICT)->where('search', $key)->pluck('parent_code')->unique();
                $cities = Region::query()->whereIn('code', $parents)
                    ->when($provinceCodes !== null, fn ($q) => $q->whereIn('parent_code', $provinceCodes))->get(['code', 'name', 'parent_code']);
            }

            if ($cities->count() > 1 && filled($district)) {
                $withDistrict = Region::query()->where('level', Region::DISTRICT)->where('search', self::searchKey($district))
                    ->whereIn('parent_code', $cities->pluck('code'))->pluck('parent_code')->all();
                $narrowed = $cities->whereIn('code', $withDistrict);
                $cities = $narrowed->isNotEmpty() ? $narrowed : $cities;
            }

            if ($cities->count() > 1 && preg_match('/^(kota|kab\.?|kabupaten)\s/u', $raw, $m)) {
                $type = $m[1] === 'kota' ? 'Kota' : 'Kabupaten';
                $narrowed = $cities->filter(fn (Region $c) => str_starts_with($c->name, $type.' '));
                $cities = $narrowed->isNotEmpty() ? $narrowed : $cities;
            }

            $provinces = $cities->pluck('parent_code')->unique();

            if ($provinces->count() !== 1) {
                return null;
            }

            $provinceName = (string) Region::query()->whereKey($provinces->first())->value('name');

            return ['name' => $cities->count() === 1 ? $cities->first()->name : null, 'province' => self::shippingProvince($provinceName)];
        });
    }

    /**
     * The provinces (as shipping names them) that have a city or regency named so, or else a district named so:
     * more than one means the name alone does not say where ("Bandung" does, "Sukamaju" may not).
     *
     * @return list<string>
     */
    public static function cityProvinces(?string $text): array
    {
        $key = self::searchKey($text);
        $key = self::CITY_ALIASES[$key] ?? $key;

        if ($key === '' || ! self::available()) {
            return [];
        }

        return Cache::remember('regions.city-provinces.'.self::version().'.'.sha1($key), 3600, function () use ($key) {
            $parents = Region::query()->where('level', Region::CITY)->where('search', $key)->pluck('parent_code');

            if ($parents->isEmpty()) {
                $cities = Region::query()->where('level', Region::DISTRICT)->where('search', $key)->pluck('parent_code')->unique();
                $parents = Region::query()->whereIn('code', $cities)->pluck('parent_code');
            }

            return Region::query()->whereIn('code', $parents->unique())->pluck('name')
                ->map(fn ($name) => self::shippingProvince((string) $name))->unique()->sort()->values()->all();
        });
    }

    /** @return list<string> every city and regency by its official name, for type-ahead suggestions */
    public static function cityNames(): array
    {
        if (! self::available()) {
            return [];
        }

        return Cache::rememberForever('regions.cities.'.self::version(), fn () => Region::query()->where('level', Region::CITY)->orderBy('name')->pluck('name')->all());
    }

    /**
     * Cities and regencies whose name contains the text, for a searchable picker: official name => "name, province".
     *
     * @return array<string, string>
     */
    public static function searchCities(string $text, int $limit = 30): array
    {
        $key = self::searchKey($text);
        $key = self::CITY_ALIASES[$key] ?? $key;

        if ($key === '' || ! self::available()) {
            return [];
        }

        $provinces = Region::query()->where('level', Region::PROVINCE)->pluck('name', 'code');

        return Region::query()->where('level', Region::CITY)->where('search', 'like', '%'.addcslashes($key, '%_\\').'%')
            ->orderByRaw('case when search = ? then 0 when search like ? then 1 else 2 end', [$key, addcslashes($key, '%_\\').'%'])->orderBy('name')
            ->limit($limit)->get(['name', 'parent_code'])
            ->mapWithKeys(fn (Region $c) => [$c->name => $c->name.', '.self::shippingProvince((string) ($provinces[$c->parent_code] ?? ''))])
            ->all();
    }

    /**
     * The rest of an address from the parts a buyer wrote: kelurahan, kecamatan, kota, provinsi and postal code,
     * as far as they point to one place. A postal code or kelurahan finds the villages; kecamatan and kota narrow
     * them. One village fills everything; villages in one kecamatan fill the kecamatan and above. A kecamatan
     * written with the city after it ("cibinong bogor") is split. Names are the official ones; the province is the
     * shipping name. Empty when nothing is imported or nothing fits.
     *
     * @param  array<string, mixed>  $parts
     * @return array{kelurahan?: string, kecamatan?: string, kota?: string, provinsi?: string, kode_pos?: string}
     */
    public static function locate(array $parts): array
    {
        if (! self::available()) {
            return [];
        }

        $get = fn (string $k) => is_string($parts[$k] ?? null) ? trim($parts[$k]) : '';
        $postal = preg_match('/^\d{5}$/', $get('kode_pos')) ? $get('kode_pos') : null;
        $village = self::searchKey($get('kelurahan'));
        $district = self::searchKey($get('kecamatan'));
        $city = self::searchKey($get('kota'));
        $city = self::CITY_ALIASES[$city] ?? $city;

        return Cache::remember('regions.locate.'.self::version().'.'.md5(json_encode([$postal, $village, $district, $city])), 3600, function () use ($postal, $village, $district, $city) {
            $districtRows = fn (string $key) => Region::query()->where('level', Region::DISTRICT)->where('search', $key)->get(['code', 'parent_code', 'name']);

            // "cibinong bogor": the longest start that is a kecamatan; the rest may be the city.
            if ($district !== '' && $districtRows($district)->isEmpty()) {
                $words = explode(' ', $district);

                for ($n = count($words) - 1; $n >= 1; $n--) {
                    $head = implode(' ', array_slice($words, 0, $n));

                    if ($districtRows($head)->isNotEmpty()) {
                        $city = $city !== '' ? $city : (self::CITY_ALIASES[$tail = implode(' ', array_slice($words, $n))] ?? $tail);
                        $district = $head;
                        break;
                    }
                }
            }

            $villages = match (true) {
                $postal !== null => Region::query()->where('level', Region::VILLAGE)->where('postal_code', $postal)->limit(200)->get(['code', 'parent_code', 'name', 'postal_code', 'search'])->toBase(),
                $village !== '' => Region::query()->where('level', Region::VILLAGE)->where('search', $village)->limit(200)->get(['code', 'parent_code', 'name', 'postal_code', 'search'])->toBase(),
                default => collect(),
            };

            $districts = $villages->isNotEmpty()
                ? Region::query()->whereIn('code', $villages->pluck('parent_code')->unique())->get(['code', 'parent_code', 'name', 'search'])->toBase()->keyBy('code')
                : ($district !== '' ? $districtRows($district)->toBase()->keyBy('code') : collect());
            $cities = Region::query()->whereIn('code', $districts->pluck('parent_code')->unique())->get(['code', 'parent_code', 'name', 'search'])->toBase()->keyBy('code');

            $keep = fn ($rows, string $key) => $key === '' ? $rows : (($narrow = $rows->filter(fn (Region $r) => $r->search === $key || str_starts_with($r->search, $key)))->isNotEmpty() ? $narrow : $rows);

            if ($villages->isNotEmpty()) {
                $villages = $keep($villages, $village);
                $districts = $keep($districts->only($villages->pluck('parent_code')->unique()->all()), $district);
                $villages = $villages->whereIn('parent_code', $districts->keys()->all());
            } else {
                $districts = $keep($districts, $district);
            }

            $cities = $keep($cities->only($districts->pluck('parent_code')->unique()->all()), $city);
            $districts = $districts->whereIn('parent_code', $cities->keys()->all());
            $villages = $villages->whereIn('parent_code', $districts->keys()->all());

            if ($cities->count() !== 1) {
                return [];
            }

            $province = (string) Region::query()->whereKey($cities->first()->parent_code)->value('name');
            $out = ['kota' => $cities->first()->name, 'provinsi' => self::shippingProvince($province)];

            if ($districts->count() === 1) {
                $out['kecamatan'] = $districts->first()->name;
            }

            if ($villages->count() === 1) {
                $out['kelurahan'] = $villages->first()->name;
            }

            if (($codes = $villages->pluck('postal_code')->filter()->unique())->count() === 1) {
                $out['kode_pos'] = (string) $codes->first();
            }

            return $out;
        });
    }
}
