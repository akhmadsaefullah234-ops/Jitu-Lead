<?php

namespace App\Console\Commands;

use App\Backup\Shell;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Proves the newest database dump can be restored, without touching the production database: `gzip -t`, the number of
 * tables it creates (at least jitu.backup.database.min_tables), then a load into a temporary database that is dropped
 * afterwards. The temporary database needs the app's database user to be allowed CREATEDB; without it that step is
 * skipped and says so.
 */
#[Signature('backup:restore-check {--file= : Dump yang diperiksa (bawaan: yang terbaru)} {--no-load : Lewati uji muat ke database sementara}')]
#[Description('Periksa dump database terbaru bisa dipulihkan (gzip -t, jumlah tabel, muat ke database sementara) tanpa menyentuh produksi')]
class BackupRestoreCheckCommand extends Command
{
    public const PREFIX = 'jitu_restore_check_';

    public function handle(Shell $shell): int
    {
        $file = $this->option('file') ?: self::newest(rtrim((string) config('jitu.backup.path'), '/'));

        if (! $file || ! is_file($file)) {
            $this->error('GAGAL  Tidak ada dump database'.($file ? ": $file" : ' di '.config('jitu.backup.path')));

            return self::FAILURE;
        }

        $this->line("Dump: $file (".number_format(filesize($file) / 1048576, 1, ',', '.').' MB, '.date('d M Y H:i', (int) filemtime($file)).')');
        $failed = false;

        [$code, $err] = $shell->run(['gzip', '-t', '--', $file], [], null, 600);
        $failed = $this->result($code === 0, 'gzip -t', $code === 0 ? 'arsip utuh' : ($err ?: "kode $code")) || $failed;

        $min = (int) config('jitu.backup.database.min_tables', 40);
        $tables = $code === 0 ? self::tables($file) : 0;
        $failed = $this->result($tables >= $min, 'Jumlah tabel', "$tables tabel (minimal $min)") || $failed;

        if ($failed || $this->option('no-load')) {
            $this->line('LEWATI Uji muat ke database sementara'.($failed ? ' (pemeriksaan di atas gagal)' : ''));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        return $this->load($shell, $file, $tables) ? self::SUCCESS : self::FAILURE;
    }

    /** The newest dump: db/database-*.sql.gz, or the database.sql.gz in the newest dated folder older versions wrote. */
    public static function newest(string $root): ?string
    {
        $dumps = glob("$root/db/database-*.sql.gz") ?: [];
        if ($dumps === []) {
            $dumps = glob("$root/[0-9]*-[0-9]*/database.sql.gz") ?: [];
        }
        usort($dumps, fn ($a, $b) => filemtime($a) <=> filemtime($b));

        return end($dumps) ?: null;
    }

    /** How many tables the dump creates. */
    public static function tables(string $file): int
    {
        $count = 0;
        $gz = gzopen($file, 'rb');

        while ($gz && ! gzeof($gz)) {
            $line = (string) gzgets($gz, 65536);
            if (str_starts_with($line, 'CREATE TABLE ') || str_starts_with($line, 'CREATE UNLOGGED TABLE ')) {
                $count++;
            }
        }
        $gz && gzclose($gz);

        return $count;
    }

    private function load(Shell $shell, string $file, int $expected): bool
    {
        $db = config('database.connections.pgsql');
        $temp = self::PREFIX.now()->format('YmdHis');

        if ($temp === $db['database'] || ! str_starts_with($temp, self::PREFIX)) {
            return ! $this->result(false, 'Uji muat', 'nama database sementara tidak aman');
        }

        $env = ['PGPASSWORD' => (string) $db['password']];
        $server = ['-h', (string) $db['host'], '-p', (string) $db['port'], '-U', (string) $db['username']];
        $conn = [...$server, '-d', $temp];
        // Created and dropped from a session of its own (the production database is only connected to, never changed).
        $admin = fn (string $sql) => $shell->run(['psql', '-X', '-q', '-v', 'ON_ERROR_STOP=1', ...$server, '-d', (string) $db['database'], '-c', $sql], $env, null, 120);

        [$created] = $admin('CREATE DATABASE "'.$temp.'"');
        if ($created !== 0) {
            $this->line("LEWATI Uji muat: pengguna database \"{$db['username']}\" tidak boleh membuat database. Sekali saja sebagai postgres: ALTER ROLE {$db['username']} CREATEDB; atau jalankan dengan --no-load.");

            return true;
        }

        try {
            [$code, $err] = $shell->run(['bash', '-c', 'set -o pipefail; gunzip -c -- "$1" | psql -X -q -v ON_ERROR_STOP=1 "${@:2}" > /dev/null', 'restore-check', $file, ...$conn], $env, null, 3600);

            if (! $this->result($code === 0, 'Uji muat', $code === 0 ? "dimuat ke $temp" : mb_substr($err ?: "kode $code", 0, 300))) {
                $out = '';
                $shell->run(['psql', '-X', '-tA', '-c', "select count(*) from pg_tables where schemaname = 'public'", ...$conn], $env, function (string $c) use (&$out) {
                    $out .= $c;
                }, 60);
                $loaded = (int) trim($out);

                return $this->result($loaded >= $expected, 'Tabel termuat', "$loaded dari $expected tabel") === false;
            }

            return false;
        } finally {
            [$dropped] = $admin('DROP DATABASE IF EXISTS "'.$temp.'"');
            if ($dropped !== 0) {
                $this->warn("Database sementara $temp belum terhapus: hapus manual (DROP DATABASE \"$temp\").");
            }
        }
    }

    /** Prints one result line. @return bool whether it failed */
    private function result(bool $ok, string $label, string $detail): bool
    {
        $ok ? $this->line("<info>LULUS </info> $label: $detail") : $this->line("<error>GAGAL </error> $label: $detail");

        return ! $ok;
    }
}
