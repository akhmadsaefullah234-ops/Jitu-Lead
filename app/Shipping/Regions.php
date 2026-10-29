<?php

namespace App\Shipping;

/**
 * Indonesia's provinces, the short names people type for them, and the province of common cities and regencies,
 * so "Bandung" or "Jabar" lands in the right shipping zone.
 */
class Regions
{
    /** Province => other ways of writing it */
    public const PROVINCES = [
        'Aceh' => ['nad', 'nanggroe aceh darussalam'],
        'Sumatera Utara' => ['sumut', 'sumatra utara'],
        'Sumatera Barat' => ['sumbar', 'sumatra barat'],
        'Riau' => [],
        'Kepulauan Riau' => ['kepri'],
        'Jambi' => [],
        'Sumatera Selatan' => ['sumsel', 'sumatra selatan'],
        'Kepulauan Bangka Belitung' => ['babel', 'bangka belitung'],
        'Bengkulu' => [],
        'Lampung' => [],
        'DKI Jakarta' => ['jakarta', 'dki'],
        'Jawa Barat' => ['jabar'],
        'Banten' => [],
        'Jawa Tengah' => ['jateng'],
        'DI Yogyakarta' => ['diy', 'yogyakarta', 'daerah istimewa yogyakarta', 'jogja', 'jogjakarta', 'yogya'],
        'Jawa Timur' => ['jatim'],
        'Bali' => [],
        'Nusa Tenggara Barat' => ['ntb'],
        'Nusa Tenggara Timur' => ['ntt'],
        'Kalimantan Barat' => ['kalbar'],
        'Kalimantan Tengah' => ['kalteng'],
        'Kalimantan Selatan' => ['kalsel'],
        'Kalimantan Timur' => ['kaltim'],
        'Kalimantan Utara' => ['kaltara'],
        'Sulawesi Utara' => ['sulut'],
        'Gorontalo' => [],
        'Sulawesi Tengah' => ['sulteng'],
        'Sulawesi Barat' => ['sulbar'],
        'Sulawesi Selatan' => ['sulsel'],
        'Sulawesi Tenggara' => ['sultra'],
        'Maluku' => [],
        'Maluku Utara' => ['malut'],
        'Papua' => [],
        'Papua Barat' => ['pabar'],
        'Papua Barat Daya' => [],
        'Papua Tengah' => [],
        'Papua Pegunungan' => [],
        'Papua Selatan' => [],
    ];

    /** Common cities and regencies (without "Kota"/"Kabupaten") => province */
    public const CITIES = [
        'DKI Jakarta' => ['jakarta', 'jakarta pusat', 'jakarta utara', 'jakarta barat', 'jakarta selatan', 'jakarta timur', 'kepulauan seribu'],
        'Jawa Barat' => ['bogor', 'depok', 'bekasi', 'cikarang', 'cibinong', 'cileungsi', 'tambun', 'bandung', 'bandung barat', 'cimahi', 'cirebon', 'sukabumi', 'tasikmalaya', 'garut', 'karawang', 'purwakarta', 'subang', 'sumedang', 'cianjur', 'indramayu', 'majalengka', 'kuningan', 'ciamis', 'banjar', 'pangandaran'],
        'Banten' => ['tangerang', 'tangerang selatan', 'tangsel', 'serpong', 'ciputat', 'pamulang', 'serang', 'cilegon', 'pandeglang', 'lebak'],
        'Jawa Tengah' => ['semarang', 'solo', 'surakarta', 'magelang', 'salatiga', 'pekalongan', 'tegal', 'purwokerto', 'banyumas', 'cilacap', 'kudus', 'jepara', 'pati', 'demak', 'kendal', 'klaten', 'sukoharjo', 'boyolali', 'karanganyar', 'sragen', 'wonogiri', 'kebumen', 'purworejo', 'wonosobo', 'temanggung', 'batang', 'brebes', 'pemalang', 'rembang', 'blora', 'grobogan', 'purbalingga', 'banjarnegara'],
        'DI Yogyakarta' => ['yogyakarta', 'jogja', 'sleman', 'bantul', 'gunungkidul', 'gunung kidul', 'kulon progo'],
        'Jawa Timur' => ['surabaya', 'malang', 'sidoarjo', 'gresik', 'mojokerto', 'jombang', 'kediri', 'blitar', 'madiun', 'ngawi', 'ponorogo', 'pacitan', 'magetan', 'nganjuk', 'tulungagung', 'trenggalek', 'lamongan', 'tuban', 'bojonegoro', 'pasuruan', 'probolinggo', 'lumajang', 'jember', 'banyuwangi', 'bondowoso', 'situbondo', 'batu', 'bangkalan', 'sampang', 'pamekasan', 'sumenep'],
        'Bali' => ['denpasar', 'badung', 'gianyar', 'tabanan', 'buleleng', 'klungkung', 'bangli', 'karangasem', 'jembrana'],
        'Aceh' => ['banda aceh', 'lhokseumawe', 'langsa'],
        'Sumatera Utara' => ['medan', 'binjai', 'pematangsiantar', 'pematang siantar', 'deli serdang', 'tebing tinggi'],
        'Sumatera Barat' => ['padang', 'bukittinggi', 'payakumbuh'],
        'Riau' => ['pekanbaru', 'dumai'],
        'Kepulauan Riau' => ['batam', 'tanjung pinang', 'tanjungpinang'],
        'Jambi' => ['jambi'],
        'Sumatera Selatan' => ['palembang', 'prabumulih', 'lubuklinggau'],
        'Kepulauan Bangka Belitung' => ['pangkal pinang', 'pangkalpinang'],
        'Bengkulu' => ['bengkulu'],
        'Lampung' => ['bandar lampung', 'metro'],
        'Kalimantan Barat' => ['pontianak', 'singkawang'],
        'Kalimantan Tengah' => ['palangkaraya', 'palangka raya'],
        'Kalimantan Selatan' => ['banjarmasin', 'banjarbaru'],
        'Kalimantan Timur' => ['samarinda', 'balikpapan', 'bontang'],
        'Kalimantan Utara' => ['tarakan', 'tanjung selor'],
        'Sulawesi Utara' => ['manado', 'bitung', 'tomohon'],
        'Gorontalo' => ['gorontalo'],
        'Sulawesi Tengah' => ['palu'],
        'Sulawesi Barat' => ['mamuju'],
        'Sulawesi Selatan' => ['makassar', 'parepare', 'gowa', 'maros', 'palopo'],
        'Sulawesi Tenggara' => ['kendari', 'baubau'],
        'Nusa Tenggara Barat' => ['mataram', 'bima', 'lombok', 'sumbawa'],
        'Nusa Tenggara Timur' => ['kupang', 'ende', 'maumere', 'labuan bajo'],
        'Maluku' => ['ambon', 'tual'],
        'Maluku Utara' => ['ternate', 'sofifi', 'tidore'],
        'Papua' => ['jayapura'],
        'Papua Barat' => ['manokwari'],
        'Papua Barat Daya' => ['sorong'],
        'Papua Tengah' => ['nabire', 'timika', 'mimika'],
        'Papua Pegunungan' => ['wamena', 'jayawijaya'],
        'Papua Selatan' => ['merauke'],
    ];

    /** @return array<string, string> for a picker */
    public static function options(): array
    {
        $names = array_keys(self::PROVINCES);

        return array_combine($names, $names);
    }

    /** "Jabar", "jawa barat" or "Prov. Jawa Barat" => "Jawa Barat"; null when it is not a province. */
    public static function province(?string $text): ?string
    {
        $key = self::key(preg_replace('/^(provinsi|prov\.?)\s+/i', '', trim((string) $text)));

        if ($key === '') {
            return null;
        }

        foreach (self::PROVINCES as $name => $aliases) {
            if ($key === self::key($name) || in_array($key, $aliases, true)) {
                return $name;
            }
        }

        return null;
    }

    /** The province of a known city or regency, or null. */
    public static function provinceOfCity(?string $city): ?string
    {
        $key = self::city($city);

        foreach (self::CITIES as $province => $cities) {
            if (in_array($key, $cities, true)) {
                return $province;
            }
        }

        return null;
    }

    /** "Kab. Bekasi", "KOTA  Bekasi" => "bekasi": how cities are compared. */
    public static function city(?string $text): string
    {
        return trim(preg_replace('/^(kota\s+administrasi|kabupaten|kab\.?|kota|kec\.?|kecamatan)\s+/', '', self::key($text)));
    }

    private static function key(?string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower((string) $text)));
    }
}
