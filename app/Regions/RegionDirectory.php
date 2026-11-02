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
}
