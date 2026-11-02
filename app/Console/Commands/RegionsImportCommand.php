<?php

namespace App\Console\Commands;

use App\Regions\RegionImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Fills (or refreshes) the regions table from the open Kemendagri region-code dataset; see docs/regions.md. */
class RegionsImportCommand extends Command
{
    protected $signature = 'regions:import
        {--wilayah= : URL or file of wilayah.sql (default: config/regions.php)}
        {--kodepos= : URL or file of wilayah_kodepos.sql (default: config/regions.php)}
        {--no-kodepos : Import without postal codes}';

    protected $description = 'Imports Indonesia\'s provinces, cities/regencies, districts and villages (with postal codes) for address dropdowns.';

    public function handle(RegionImporter $importer): int
    {
        try {
            $wilayah = $this->read($this->option('wilayah') ?: config('regions.sources.wilayah'));
            $kodepos = $this->option('no-kodepos') ? null : $this->read($this->option('kodepos') ?: config('regions.sources.kodepos'));
            $counts = $importer->import($wilayah, $kodepos);
        } catch (Throwable $e) {
            $this->error('Impor wilayah gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Wilayah diimpor: %d provinsi, %d kota/kabupaten, %d kecamatan, %d kelurahan/desa (%d dengan kode pos).',
            $counts['provinces'], $counts['cities'], $counts['districts'], $counts['villages'], $counts['postal_codes'],
        ));

        return self::SUCCESS;
    }

    private function read(string $source): string
    {
        if (preg_match('#^https?://#i', $source)) {
            $this->line('Mengunduh '.$source.' ...');

            return Http::timeout(180)->retry(2, 2000)->get($source)->throw()->body();
        }

        if (! is_file($source) || ! is_readable($source)) {
            throw new \RuntimeException('Berkas tidak ditemukan: '.$source);
        }

        return (string) file_get_contents($source);
    }
}
