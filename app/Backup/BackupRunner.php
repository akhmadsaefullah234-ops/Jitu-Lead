<?php

namespace App\Backup;

use App\Mail\BackupFailed;
use App\Models\BackupRun;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The daily backup: database dump, uploaded files and (when present) the
 * WhatsApp gateway sessions go into a dated folder, old folders are rotated,
 * and the folder is copied off the server through rclone when one is set up.
 * Every step is recorded; a failure emails SUPPORT_EMAIL.
 */
class BackupRunner
{
    /** @var list<array{label: string, status: string, note: ?string, size: int}> */
    private array $steps = [];

    public function __construct(private Shell $shell) {}

    public function __invoke(): BackupRun
    {
        $this->steps = [];
        $run = BackupRun::create(['status' => BackupRun::RUNNING, 'started_at' => now()]);
        $config = config('jitu.backup');
        $folder = rtrim($config['path'], '/').'/'.now()->timezone(config('app.timezone'))->format('Ymd-His');

        try {
            if (! is_dir($folder) && ! @mkdir($folder, 0700, true)) {
                throw new \RuntimeException("Folder cadangan tidak bisa dibuat: {$config['path']}");
            }
            @chmod(rtrim($config['path'], '/'), 0700);

            $this->database($folder);
            $this->uploads($folder);
            $this->gateway($folder, $config['gateway_path']);
            $this->rotate($config['path'], (int) $config['keep_days']);
            $this->offsite($folder, $config);
        } catch (Throwable $e) {
            $this->steps[] = ['label' => 'Pencadangan', 'status' => 'failed', 'note' => $e->getMessage(), 'size' => 0];
        }

        return $this->finish($run, $folder);
    }

    private function database(string $folder): void
    {
        $db = config('database.connections.pgsql');
        $file = "$folder/database.sql.gz";
        $gz = gzopen($file, 'wb6');
        chmod($file, 0600);

        [$code, $err] = $this->shell->run(
            ['pg_dump', '--no-owner', '--no-privileges', '-h', (string) $db['host'], '-p', (string) $db['port'], '-U', (string) $db['username'], (string) $db['database']],
            ['PGPASSWORD' => (string) $db['password']],
            fn (string $chunk) => gzwrite($gz, $chunk),
        );
        gzclose($gz);

        $this->record('Database (pg_dump)', $code === 0 && filesize($file) > 0, $code === 0 ? null : ($err ?: "kode $code"), $file);
    }

    private function uploads(string $folder): void
    {
        $file = "$folder/storage.tar.gz";
        [$code, $err] = $this->shell->run(['tar', '-czf', $file, '-C', storage_path('app'), '--exclude=./backups', '.']);
        @chmod($file, 0600);

        // tar exits 1 when a file changed while it was being read; the archive is still usable.
        $this->record('Unggahan (storage/app)', in_array($code, [0, 1], true) && is_file($file), $code === 0 ? null : ($err ?: "kode $code"), $file);
    }

    private function gateway(string $folder, ?string $path): void
    {
        if (! $path || ! is_dir($path)) {
            $this->steps[] = ['label' => 'Sesi WhatsApp gateway', 'status' => 'skipped', 'note' => 'folder tidak ada (gateway tidak dipasang)', 'size' => 0];

            return;
        }

        if (! is_readable($path)) {
            $this->steps[] = ['label' => 'Sesi WhatsApp gateway', 'status' => 'warning', 'note' => "tidak bisa dibaca oleh aplikasi: beri akses baca ke $path (lihat docs/deploy.md)", 'size' => 0];

            return;
        }

        $file = "$folder/gateway.tar.gz";
        [$code, $err] = $this->shell->run(['tar', '-czf', $file, '-C', $path, '.']);
        @chmod($file, 0600);

        $this->record('Sesi WhatsApp gateway', in_array($code, [0, 1], true) && is_file($file), $code === 0 ? null : ($err ?: "kode $code"), $file);
    }

    private function rotate(string $path, int $keepDays): void
    {
        $removed = 0;

        foreach (glob(rtrim($path, '/').'/[0-9]*-[0-9]*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (preg_match('/\/\d{8}-\d{6}$/', $dir) && filemtime($dir) < now()->subDays($keepDays)->getTimestamp()) {
                foreach (glob("$dir/*") ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($dir);
                $removed++;
            }
        }

        $this->steps[] = ['label' => "Rotasi lokal ($keepDays hari)", 'status' => 'ok', 'note' => $removed ? "$removed cadangan lama dihapus" : null, 'size' => 0];
    }

    private function offsite(string $folder, array $config): void
    {
        $remote = trim((string) $config['rclone_remote']);

        if ($remote === '') {
            $this->steps[] = ['label' => 'Salinan di luar server', 'status' => 'warning', 'note' => 'belum diatur (BACKUP_RCLONE_REMOTE kosong): cadangan hanya ada di server ini', 'size' => 0];

            return;
        }

        if (str_starts_with($remote, '-') || ! str_contains($remote, ':')) {
            $this->steps[] = ['label' => 'Salinan di luar server', 'status' => 'failed', 'note' => 'BACKUP_RCLONE_REMOTE harus berbentuk "remote:folder"', 'size' => 0];

            return;
        }

        if (! $this->shell->exists('rclone')) {
            $this->steps[] = ['label' => 'Salinan di luar server', 'status' => 'failed', 'note' => 'rclone belum terpasang di server (apt-get install rclone)', 'size' => 0];

            return;
        }

        $env = $config['rclone_env'];
        $target = rtrim($remote, '/').'/'.basename($folder);
        [$code, $err] = $this->shell->run(['rclone', 'copy', '--', $folder, $target], $env);

        if ($code === 0 && ($days = (int) $config['remote_keep_days']) > 0) {
            $this->shell->run(['rclone', 'delete', '--min-age', $days.'d', '--', rtrim($remote, '/')], $env);
            $this->shell->run(['rclone', 'rmdirs', '--leave-root', '--', rtrim($remote, '/')], $env);
        }

        $this->steps[] = ['label' => 'Salinan di luar server', 'status' => $code === 0 ? 'ok' : 'failed', 'note' => $code === 0 ? "ke $remote" : $this->scrub($err ?: "kode $code"), 'size' => 0];
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

    private function finish(BackupRun $run, string $folder): BackupRun
    {
        $failed = collect($this->steps)->where('status', 'failed');
        $warned = collect($this->steps)->where('status', 'warning');
        $status = $failed->isNotEmpty() ? BackupRun::FAILED : ($warned->isNotEmpty() ? BackupRun::WARNING : BackupRun::OK);

        $run->update([
            'status' => $status, 'finished_at' => now(), 'folder' => $folder, 'steps' => $this->steps,
            'size_bytes' => (int) collect($this->steps)->sum('size'),
            'message' => match ($status) {
                BackupRun::FAILED => 'Gagal: '.$failed->map(fn ($s) => $s['label'])->implode(', '),
                BackupRun::WARNING => $warned->map(fn ($s) => $s['label'].': '.$s['note'])->implode('; '),
                default => null,
            },
        ]);

        if ($status === BackupRun::FAILED && ($to = config('jitu.support_email'))) {
            try {
                Mail::to($to)->send(new BackupFailed($run));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $run;
    }
}
