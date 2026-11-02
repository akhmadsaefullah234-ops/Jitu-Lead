<?php

namespace App\Regions;

use App\Models\Region;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reads the open "wilayah" dataset (SQL dumps of `INSERT ... VALUES ('32.04.05','Name'), ...`) and replaces the
 * regions table with it. Running it again with a newer dump updates everything; nothing else points at the rows
 * by id, since forms and leads keep codes and names.
 */
class RegionImporter
{
    private const CODE = '\d{2}(?:\.\d{2}(?:\.\d{2}(?:\.\d{4})?)?)?';

    /**
     * @return array{provinces: int, cities: int, districts: int, villages: int, postal_codes: int}
     */
    public function import(string $wilayahSql, ?string $postalSql = null): array
    {
        $rows = $this->parseRegions($wilayahSql);
        $postal = $postalSql !== null ? $this->parsePostalCodes($postalSql) : [];
        $counts = array_count_values(array_column($rows, 'level'));

        if (($counts[Region::PROVINCE] ?? 0) === 0) {
            throw new RuntimeException('Tidak ada data provinsi di sumber wilayah. Periksa alamat atau berkas sumbernya.');
        }

        $withPostal = 0;
        foreach ($rows as &$row) {
            if ($row['level'] === Region::VILLAGE && isset($postal[$row['code']])) {
                $row['postal_code'] = $postal[$row['code']];
                $withPostal++;
            }
        }
        unset($row);

        DB::transaction(function () use ($rows) {
            Region::query()->delete();

            foreach (array_chunk($rows, 1000) as $chunk) {
                Region::query()->insert($chunk);
            }
        });

        RegionDirectory::flush();

        return [
            'provinces' => $counts[Region::PROVINCE] ?? 0,
            'cities' => $counts[Region::CITY] ?? 0,
            'districts' => $counts[Region::DISTRICT] ?? 0,
            'villages' => $counts[Region::VILLAGE] ?? 0,
            'postal_codes' => $withPostal,
        ];
    }

    /** @return list<array{code: string, parent_code: ?string, level: int, name: string, search: string, postal_code: ?string}> */
    public function parseRegions(string $sql): array
    {
        preg_match_all("/\\(\\s*'(".self::CODE.")'\\s*,\\s*'((?:[^']|'')*)'\\s*\\)/", $sql, $m, PREG_SET_ORDER);
        $rows = [];

        foreach ($m as [, $code, $name]) {
            $name = trim(preg_replace('/\s+/', ' ', str_replace("''", "'", $name)));

            if ($name === '' || isset($rows[$code])) {
                continue;
            }

            $level = substr_count($code, '.') + 1;
            $rows[$code] = [
                'code' => $code,
                'parent_code' => $level > 1 ? substr($code, 0, strrpos($code, '.')) : null,
                'level' => $level,
                'name' => mb_substr($name, 0, 100),
                'search' => mb_substr(RegionDirectory::searchKey($name), 0, 100),
                'postal_code' => null,
            ];
        }

        // A region whose parent is missing would be unreachable from the dropdowns.
        return array_values(array_filter($rows, fn (array $r) => $r['parent_code'] === null || isset($rows[$r['parent_code']])));
    }

    /** @return array<string, string> village code => postal code */
    public function parsePostalCodes(string $sql): array
    {
        preg_match_all("/\\(\\s*'(\\d{2}\\.\\d{2}\\.\\d{2}\\.\\d{4})'\\s*,\\s*'(\\d{5})'\\s*\\)/", $sql, $m, PREG_SET_ORDER);
        $out = [];

        foreach ($m as [, $code, $postal]) {
            $out[$code] = $postal;
        }

        return $out;
    }
}
