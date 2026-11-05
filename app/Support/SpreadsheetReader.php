<?php

namespace App\Support;

use InvalidArgumentException;
use SimpleXMLElement;
use ZipArchive;

/**
 * The rows of an uploaded CSV or XLSX file (the first sheet), as lists of strings, header first. No library: an
 * XLSX is a zip of XML; only the cell values are read (shared strings, inline strings, numbers as written).
 */
class SpreadsheetReader
{
    /** @return list<list<string>> */
    public static function rows(string $path, ?string $name = null, int $max = 2000): array
    {
        $name ??= $path;
        $rows = str_ends_with(mb_strtolower($name), '.xlsx') || self::isZip($path) ? self::xlsx($path, $max) : self::csv($path, $max);

        // Trailing empty rows and cells are dropped; every row is as wide as the header.
        $rows = array_values(array_filter($rows, fn (array $r) => implode('', $r) !== ''));
        $width = count($rows[0] ?? []);

        return array_map(fn (array $r) => array_slice(array_pad($r, $width, ''), 0, max($width, 1)), $rows);
    }

    private static function isZip(string $path): bool
    {
        $head = (string) @file_get_contents($path, false, null, 0, 4);

        return $head === "PK\x03\x04";
    }

    /** @return list<list<string>> */
    private static function csv(string $path, int $max): array
    {
        $handle = fopen($path, 'rb') ?: throw new InvalidArgumentException('File tidak bisa dibaca.');

        try {
            $first = preg_replace('/^\xEF\xBB\xBF/', '', (string) fgets($handle));
            $delimiter = collect([';', ',', "\t"])->sortByDesc(fn ($d) => substr_count($first, $d))->first();
            $rows = [array_map(fn ($c) => trim((string) $c), str_getcsv(rtrim($first, "\r\n"), $delimiter, '"', ''))];

            while (count($rows) <= $max && ($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $rows[] = array_map(fn ($c) => trim((string) $c), $cells);
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @return list<list<string>> */
    private static function xlsx(string $path, int $max): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('File XLSX tidak bisa dibuka.');
        }

        try {
            $strings = [];
            if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                foreach (self::xml($xml)->si as $si) {
                    $strings[] = trim(isset($si->t) ? (string) $si->t : implode('', array_map('strval', iterator_to_array($si->xpath('.//*[local-name()="t"]') ?: []))));
                }
            }

            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheet === false) {
                $first = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->first(fn ($n) => str_starts_with((string) $n, 'xl/worksheets/sheet'));
                $sheet = $first ? $zip->getFromName($first) : false;
            }

            if ($sheet === false) {
                throw new InvalidArgumentException('Sheet pertama tidak ditemukan di file XLSX.');
            }

            $rows = [];
            foreach (self::xml($sheet)->sheetData->row ?? [] as $row) {
                if (count($rows) > $max) {
                    break;
                }

                $cells = [];
                foreach ($row->c as $c) {
                    $index = self::column(preg_replace('/\d+/', '', (string) $c['r'])) ?? count($cells);
                    $type = (string) $c['t'];
                    $value = match ($type) {
                        's' => $strings[(int) $c->v] ?? '',
                        'inlineStr' => (string) ($c->is->t ?? ''),
                        default => (string) $c->v,
                    };
                    $cells = array_pad($cells, $index, '');
                    $cells[$index] = trim($value);
                }
                $rows[] = $cells;
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private static function xml(string $xml): SimpleXMLElement
    {
        $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

        if ($doc === false) {
            throw new InvalidArgumentException('Isi file XLSX tidak terbaca.');
        }

        return $doc;
    }

    /** "A" => 0, "AB" => 27. */
    private static function column(string $letters): ?int
    {
        if ($letters === '') {
            return null;
        }

        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }
}
