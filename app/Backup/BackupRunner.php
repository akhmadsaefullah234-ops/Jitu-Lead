<?php

namespace App\Backup;

use App\Models\BackupRun;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Backups, in two kinds:
 *
 * - DATABASE (backup:database, every few hours): pg_dump into db/, checked with `gzip -t`, then copied to <remote>/db
 *   on its own, before anything big.
 * - FULL (backup:run, 02:00 WIB): the same dump first, then the WhatsApp gateway sessions as a tarball in sessions/
 *   (copied to <remote>/sessions), and storage/app mirrored to <remote>/storage with `rclone sync`, so only what
 *   changed is sent; files deleted or replaced locally move to <remote>/storage-deleted/<date> and stay there
 *   remote_keep_days. Without a remote, storage/app is a tarball in storage/ as before.
 *
 * Old copies are removed before anything is uploaded, so a full bucket never locks itself, and the newest copy of
 * each kind is never removed however old it is. Local copies are rotated by age and, when the disk gets full, oldest
 * first, never the newest valid dump. Every step is recorded; a failure reaches the owner by push and email.
 */
class BackupRunner
{
    public const FULL = 'full';

    public const DATABASE = 'database';

    private const DUMP = '/^database-(\d{8}-\d{6})\.sql\.gz$/';

    private const SESSIONS = '/^gateway-(\d{8}-\d{6})\.tar\.gz$/';

    private const UPLOADS = '/^storage-(\d{8}-\d{6})\.tar\.gz$/';

    /** The dated folders older versions wrote, locally and on the remote. */
    private const LEGACY = '/^(\d{8}-\d{6})$/';

    /** @var list<array{label: string, status: string, note: ?string, size: int}> */
    private array $steps = [];

    /** The dump this run made, once it passed `gzip -t`. */
    private ?string $dump = null;

    private bool $remoteHasDump = false;

    private string $stamp = '';

    public function __construct(private Shell $shell, private DatabaseOwnership $ownership) {}

    public function __invoke(string $kind = self::FULL): BackupRun
    {
        $this->steps = [];
        $this->dump = null;
        $this->remoteHasDump = false;
        $run = BackupRun::create(['kind' => $kind, 'status' => BackupRun::RUNNING, 'started_at' => now()]);
        $config = config('jitu.backup');
        $root = rtrim((string) $config['path'], '/');
        $this->stamp = now()->timezone(config('app.timezone'))->format('Ymd-His');

        try {
            $this->directory($root);
            $remote = $this->remote($config);

            $this->database($root);
            if ($remote !== null) {
                $this->databaseOffsite($remote, $config);
            }

            if ($kind === self::FULL) {
                $sessions = $this->gateway($root, $config['gateway_path']);

                if ($remote !== null) {
                    $this->sessionsOffsite($remote, $sessions, $config);
                    $this->mirror($remote, $root, $config);
                    $this->legacyOffsite($remote, $config);
                } else {
                    $this->uploads($root);
                }
            }

            $this->rotate($root, $config);
            $this->disk($root, $config);
        } catch (Throwable $e) {
            $this->steps[] = ['label' => 'Pencadangan', 'status' => 'failed', 'note' => $this->scrub($e->getMessage()), 'size' => 0];
        }

        return $this->finish($run, $root);
    }

    // ---- Database -------------------------------------------------------------------------------------------------

    private function database(string $root): void
    {
        $this->directory($dir = "$root/db");
        $skip = $this->ownershipCheck();

        $db = config('database.connections.pgsql');
        $file = "$dir/database-{$this->stamp}.sql.gz";
        $gz = gzopen($file, 'wb6');
        chmod($file, 0600);

        $command = ['pg_dump', '--no-owner', '--no-privileges', '-h', (string) $db['host'], '-p', (string) $db['port'], '-U', (string) $db['username']];
        foreach ($skip as $relation) {
            array_push($command, '--exclude-table', $relation);
        }
        $command[] = (string) $db['database'];

        [$code, $err] = $this->shell->run($command, ['PGPASSWORD' => (string) $db['password']], fn (string $chunk) => gzwrite($gz, $chunk));
        gzclose($gz);

        $ok = $code === 0 && filesize($file) > 0;
        $note = $ok ? null : ($err ?: "kode $code");

        if ($ok) {
            [$test, $testErr] = $this->shell->run(['gzip', '-t', '--', $file], [], null, 600);
            if ($test !== 0) {
                [$ok, $note] = [false, 'hasil dump rusak (gzip -t gagal)'.($testErr ? ": $testErr" : '')];
            }
        }

        $size = $ok ? (int) filesize($file) : 0;
        $ok ? $this->dump = $file : @unlink($file);

        $this->steps[] = ['label' => 'Database (pg_dump)', 'status' => $ok ? 'ok' : 'failed', 'note' => $note ? $this->scrub($note) : null, 'size' => $size];
    }

    /**
     * Names what the app's database user does not own. What it cannot even read would stop pg_dump, so it is left out
     * of the dump (and named); the rest of the database is still saved.
     *
     * @return list<string> pg_dump patterns to exclude
     */
    private function ownershipCheck(): array
    {
        try {
            $foreign = $this->ownership->foreign();
        } catch (Throwable $e) {
            $this->steps[] = ['label' => 'Kepemilikan tabel', 'status' => 'warning', 'note' => 'tidak bisa diperiksa: '.class_basename($e), 'size' => 0];

            return [];
        }

        if ($foreign === []) {
            return [];
        }

        $user = $this->ownership->user();
        $unreadable = array_values(array_filter($foreign, fn ($r) => ! $r['readable']));
        $note = count($foreign).' objek bukan milik pengguna aplikasi "'.$user.'": '
            .collect($foreign)->map(fn ($r) => "{$r['name']} (pemilik {$r['owner']})")->implode(', ').'. '
            .($unreadable !== [] ? 'Tidak bisa dibaca, jadi TIDAK ikut dicadangkan: '.collect($unreadable)->pluck('name')->implode(', ').'. ' : 'Masih ikut dicadangkan. ')
            .'Perbaiki sebagai postgres: ALTER TABLE <nama> OWNER TO '.$user.'; atau hapus bila hanya salinan.';
        $this->steps[] = ['label' => 'Kepemilikan tabel', 'status' => 'warning', 'note' => mb_substr($note, 0, 400), 'size' => 0];

        return array_map(fn ($r) => '"'.str_replace('"', '""', $r['name']).'"', $unreadable);
    }

    private function databaseOffsite(string $remote, array $config): void
    {
        $pruned = $this->prune("$remote/db", self::DUMP, (int) $config['database']['remote_keep_days'], dirs: false);
        $this->remoteHasDump = $pruned['count'] > 0;

        if ($this->dump === null) {
            $this->steps[] = ['label' => 'Database ke luar server', 'status' => $pruned['error'] ? 'failed' : 'skipped', 'note' => $pruned['error'] ?? 'tidak ada dump baru untuk dikirim', 'size' => 0];

            return;
        }

        [$code, $err] = $this->shell->run(['rclone', 'copy', '--', $this->dump, "$remote/db"], $config['rclone_env']);
        $this->remoteHasDump = $this->remoteHasDump || $code === 0;
        $this->offsiteStep('Database ke luar server', $code, $err, "$remote/db", $pruned);
    }

    // ---- Gateway sessions and uploads -----------------------------------------------------------------------------

    private function gateway(string $root, ?string $path): ?string
    {
        if (! $path || ! is_dir($path)) {
            $this->steps[] = ['label' => 'Sesi WhatsApp gateway', 'status' => 'skipped', 'note' => 'folder tidak ada (gateway tidak dipasang)', 'size' => 0];

            return null;
        }

        if (! is_readable($path)) {
            $this->steps[] = ['label' => 'Sesi WhatsApp gateway', 'status' => 'warning', 'note' => "tidak bisa dibaca oleh aplikasi: beri akses baca ke $path (lihat docs/deploy.md)", 'size' => 0];

            return null;
        }

        $this->directory($dir = "$root/sessions");
        $file = "$dir/gateway-{$this->stamp}.tar.gz";
        [$code, $err] = $this->shell->run(['tar', '-czf', $file, '-C', $path, '.']);
        @chmod($file, 0600);

        // tar exits 1 when a file changed while it was being read; the archive is still usable.
        $ok = in_array($code, [0, 1], true) && is_file($file);
        $this->record('Sesi WhatsApp gateway', $ok, $code === 0 ? null : ($err ?: "kode $code"), $file);

        return $ok ? $file : null;
    }

    private function sessionsOffsite(string $remote, ?string $file, array $config): void
    {
        $pruned = $this->prune("$remote/sessions", self::SESSIONS, (int) $config['sessions_remote_keep_days'], dirs: false);

        if ($file === null) {
            if ($pruned['error']) {
                $this->steps[] = ['label' => 'Sesi WhatsApp ke luar server', 'status' => 'failed', 'note' => $pruned['error'], 'size' => 0];
            }

            return;
        }

        [$code, $err] = $this->shell->run(['rclone', 'copy', '--', $file, "$remote/sessions"], $config['rclone_env']);
        $this->offsiteStep('Sesi WhatsApp ke luar server', $code, $err, "$remote/sessions", $pruned);
    }

    /** storage/app mirrored: only changed files travel; what was deleted or replaced is kept aside remote_keep_days. */
    private function mirror(string $remote, string $root, array $config): void
    {
        $pruned = $this->prune("$remote/storage-deleted", self::LEGACY, (int) $config['remote_keep_days'], dirs: true, keepNewest: false);

        $command = ['rclone', 'sync', '--backup-dir', "$remote/storage-deleted/{$this->stamp}"];
        if (($inside = $this->insideStorage($root)) !== null) {
            array_push($command, '--exclude', "/$inside/**");
        }
        array_push($command, '--', storage_path('app'), "$remote/storage");

        [$code, $err] = $this->shell->run($command, $config['rclone_env'], null, 4 * 3600);
        $this->offsiteStep('Unggahan (cermin storage/app)', $code, $err, "$remote/storage", $pruned);
    }

    /** Without a remote there is nothing to mirror to: storage/app becomes a local tarball, as before. */
    private function uploads(string $root): void
    {
        $this->directory($dir = "$root/storage");
        $file = "$dir/storage-{$this->stamp}.tar.gz";
        $command = ['tar', '-czf', $file, '-C', storage_path('app')];
        if (($inside = $this->insideStorage($root)) !== null) {
            $command[] = "--exclude=./$inside";
        }
        $command[] = '.';

        [$code, $err] = $this->shell->run($command);
        @chmod($file, 0600);

        $this->record('Unggahan (storage/app)', in_array($code, [0, 1], true) && is_file($file), $code === 0 ? null : ($err ?: "kode $code"), $file);
    }

    /** The dated folders older versions uploaded every night (the bulk of the bucket). */
    private function legacyOffsite(string $remote, array $config): void
    {
        $pruned = $this->prune($remote, self::LEGACY, (int) $config['legacy_remote_keep_days'], dirs: true, keepNewest: ! $this->remoteHasDump);

        if ($pruned['error'] !== null || $pruned['removed'] > 0) {
            $this->steps[] = ['label' => 'Salinan lama di luar server', 'status' => $pruned['error'] ? 'warning' : 'ok',
                'note' => $pruned['error'] ?? "{$pruned['removed']} folder bertanggal lama dihapus", 'size' => 0];
        }
    }

    // ---- Remote ---------------------------------------------------------------------------------------------------

    /** The remote to use, or null (recorded) when none is set up or it cannot be used. */
    private function remote(array $config): ?string
    {
        $remote = rtrim(trim((string) $config['rclone_remote']), '/');

        $problem = match (true) {
            $remote === '' => ['warning', 'belum diatur (BACKUP_RCLONE_REMOTE kosong): cadangan hanya ada di server ini'],
            str_starts_with($remote, '-') || ! str_contains($remote, ':') => ['failed', 'BACKUP_RCLONE_REMOTE harus berbentuk "remote:folder"'],
            ! $this->shell->exists('rclone') => ['failed', 'rclone belum terpasang di server (apt-get install rclone)'],
            default => null,
        };

        if ($problem !== null) {
            $this->steps[] = ['label' => 'Salinan di luar server', 'status' => $problem[0], 'note' => $problem[1], 'size' => 0];

            return null;
        }

        return $remote;
    }

    /**
     * Removes copies in $dir older than $days, named by $pattern (its first group is the date). Runs before the upload,
     * so it frees space even when the upload then fails; the newest copy stays however old it is, unless $keepNewest
     * is off.
     *
     * @return array{count: int, removed: int, error: ?string}
     */
    private function prune(string $dir, string $pattern, int $days, bool $dirs, bool $keepNewest = true): array
    {
        $env = config('jitu.backup.rclone_env');
        $out = '';
        [$code, $err] = $this->shell->run(['rclone', 'lsf', $dirs ? '--dirs-only' : '--files-only', '--', $dir], $env, function (string $chunk) use (&$out) {
            $out .= $chunk;
        }, 600);

        // 3 = the folder does not exist yet: nothing to remove.
        if ($code !== 0) {
            return ['count' => 0, 'removed' => 0, 'error' => $code === 3 ? null : 'daftar salinan lama tidak terbaca: '.$this->scrub($err ?: "kode $code")];
        }

        $found = [];
        foreach (preg_split('/\R/', $out) ?: [] as $line) {
            $name = rtrim(trim($line), '/');
            if (preg_match($pattern, $name, $m) && ($at = self::stampTime($m[1])) !== null) {
                $found[$name] = $at;
            }
        }

        arsort($found);
        $cutoff = now()->subDays($days)->getTimestamp();
        $removed = 0;
        $error = null;

        foreach (array_slice($found, $keepNewest ? 1 : 0, null, true) as $name => $at) {
            if ($days <= 0 || $at >= $cutoff) {
                continue;
            }

            [$code, $err] = $this->shell->run(['rclone', $dirs ? 'purge' : 'deletefile', '--', "$dir/$name"], $env);
            $code === 0 ? $removed++ : $error ??= 'salinan lama gagal dihapus: '.$this->scrub($err ?: "kode $code");
        }

        return ['count' => count($found) - $removed, 'removed' => $removed, 'error' => $error];
    }

    /** @param  array{count: int, removed: int, error: ?string}  $pruned */
    private function offsiteStep(string $label, int $code, string $err, string $target, array $pruned): void
    {
        $notes = array_filter([
            $code === 0 ? "ke $target" : $this->scrub($err ?: "kode $code"),
            $pruned['removed'] > 0 ? "{$pruned['removed']} salinan lama dihapus" : null,
            $pruned['error'],
        ]);

        $this->steps[] = ['label' => $label, 'status' => $code !== 0 ? 'failed' : ($pruned['error'] ? 'warning' : 'ok'), 'note' => implode('; ', $notes), 'size' => 0];
    }

    // ---- Local retention ------------------------------------------------------------------------------------------

    private function rotate(string $root, array $config): void
    {
        $keep = (int) $config['keep_days'];
        $removed = 0;

        foreach ([['db', (int) $config['database']['keep_days']], ['sessions', $keep], ['storage', $keep], ['', $keep]] as [$kind, $days]) {
            $items = array_filter($this->local($root), fn ($i) => $i['kind'] === ($kind ?: 'legacy'));
            // The newest of each kind stays, however old (an old dated folder only while no new dump exists).
            $newest = array_key_last($items);
            $protect = $kind === '' ? ($this->local($root, 'db') === [] ? $newest : null) : $newest;

            foreach ($items as $path => $item) {
                if ($path !== $protect && $days > 0 && $item['time'] < now()->subDays($days)->getTimestamp()) {
                    $this->remove($path);
                    $removed++;
                }
            }
        }

        $this->steps[] = ['label' => "Rotasi lokal ({$config['database']['keep_days']} hari database, $keep hari lainnya)", 'status' => 'ok', 'note' => $removed ? "$removed cadangan lama dihapus" : null, 'size' => 0];
    }

    /** Less free space than the minimum: oldest local copies go first, never the newest valid dump. */
    private function disk(string $root, array $config): void
    {
        $min = (float) $config['disk_min_free_percent'];
        $free = $this->shell->freePercent($root);

        if ($free === null) {
            $this->steps[] = ['label' => 'Ruang disk', 'status' => 'warning', 'note' => 'ruang disk tidak terbaca', 'size' => 0];

            return;
        }

        if ($free >= $min) {
            $this->steps[] = ['label' => 'Ruang disk', 'status' => 'ok', 'note' => "sisa {$free}%", 'size' => 0];

            return;
        }

        $before = $free;
        $newestDump = array_key_last($this->local($root, 'db'));
        $items = array_filter($this->local($root), fn ($i, $path) => $path !== $newestDump, ARRAY_FILTER_USE_BOTH);
        uasort($items, fn ($a, $b) => $a['time'] <=> $b['time']);
        $removed = 0;

        foreach (array_keys($items) as $path) {
            if ($free >= $min) {
                break;
            }
            $this->remove($path);
            $removed++;
            $free = $this->shell->freePercent($root) ?? $free;
        }

        $this->steps[] = ['label' => 'Ruang disk', 'status' => 'warning', 'size' => 0, 'note' => "sisa {$before}% (batas {$min}%): $removed cadangan lokal tertua dihapus"
            .($free >= $min ? ", sekarang {$free}%" : ", masih {$free}%: kosongkan disk server")];
    }

    /**
     * Local copies, oldest first: path => kind and time.
     *
     * @return array<string, array{kind: string, time: int}>
     */
    private function local(string $root, ?string $only = null): array
    {
        $items = [];
        $sources = ['db' => self::DUMP, 'sessions' => self::SESSIONS, 'storage' => self::UPLOADS];

        foreach ($sources as $kind => $pattern) {
            foreach (glob("$root/$kind/*") ?: [] as $file) {
                if (is_file($file) && preg_match($pattern, basename($file))) {
                    $items[$file] = ['kind' => $kind, 'time' => (int) filemtime($file)];
                }
            }
        }

        foreach (glob("$root/[0-9]*-[0-9]*", GLOB_ONLYDIR) ?: [] as $dir) {
            if (preg_match(self::LEGACY, basename($dir))) {
                $items[$dir] = ['kind' => 'legacy', 'time' => (int) filemtime($dir)];
            }
        }

        if ($only !== null) {
            $items = array_filter($items, fn ($i) => $i['kind'] === $only);
        }

        uasort($items, fn ($a, $b) => $a['time'] <=> $b['time']);

        return $items;
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (glob("$path/*") ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }

    // ---- Helpers --------------------------------------------------------------------------------------------------

    private function directory(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true)) {
            throw new \RuntimeException("Folder cadangan tidak bisa dibuat: $dir");
        }
        @chmod($dir, 0700);
    }

    /** The backup folder's path inside storage/app ("backups"), or null when it lies elsewhere. */
    private function insideStorage(string $root): ?string
    {
        $storage = rtrim(storage_path('app'), '/');

        return str_starts_with($root, $storage.'/') ? substr($root, strlen($storage) + 1) : null;
    }

    public static function stampTime(string $stamp): ?int
    {
        try {
            return CarbonImmutable::createFromFormat('Ymd-His', $stamp, config('app.timezone'))?->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    private function record(string $label, bool $ok, ?string $note, string $file): void
    {
        $this->steps[] = ['label' => $label, 'status' => $ok ? 'ok' : 'failed', 'note' => $note ? $this->scrub($note) : null, 'size' => is_file($file) ? (int) filesize($file) : 0];
    }

    /** Error text can echo a connection string or key; never store those. */
    private function scrub(string $text): string
    {
        foreach (array_merge([(string) config('database.connections.pgsql.password')], array_values(config('jitu.backup.rclone_env'))) as $secret) {
            if (strlen($secret) >= 4) {
                $text = str_replace($secret, '***', $text);
            }
        }

        return mb_substr($text, 0, 400);
    }

    private function finish(BackupRun $run, string $root): BackupRun
    {
        $failed = collect($this->steps)->where('status', 'failed');
        $warned = collect($this->steps)->where('status', 'warning');
        $status = $failed->isNotEmpty() ? BackupRun::FAILED : ($warned->isNotEmpty() ? BackupRun::WARNING : BackupRun::OK);

        $run->update([
            'status' => $status, 'finished_at' => now(), 'folder' => $root, 'steps' => $this->steps,
            'database_file' => $this->dump, 'database_bytes' => $this->dump ? (int) filesize($this->dump) : null,
            'size_bytes' => (int) collect($this->steps)->sum('size'),
            'message' => match ($status) {
                BackupRun::FAILED => 'Gagal: '.$failed->map(fn ($s) => $s['label'])->implode(', '),
                BackupRun::WARNING => $warned->map(fn ($s) => $s['label'].': '.$s['note'])->implode('; '),
                default => null,
            },
        ]);

        if ($status === BackupRun::FAILED) {
            BackupAlerts::failed($run);
        }

        return $run;
    }
}
