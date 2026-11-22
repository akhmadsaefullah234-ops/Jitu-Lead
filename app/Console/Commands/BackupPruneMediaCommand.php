<?php

namespace App\Console\Commands;

use App\Backup\Shell;
use App\Models\Tenant;
use App\Models\WaMessage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * WhatsApp media (wa-media/<tenant>/…) older than N days, by hand only: a dry run unless --apply, and in production
 * --apply asks again unless --force. Every run writes the list of files to storage/logs/media-prune-*.log.
 *
 * - archive (default): the files are moved to <BACKUP_RCLONE_REMOTE>/media-archive first; nothing is removed locally
 *   unless that move succeeded.
 * - delete: the files are removed. The nightly mirror still keeps them in <remote>/storage-deleted remote_keep_days.
 *
 * The chat then shows that the file was archived or removed instead of a broken picture.
 */
#[Signature('backup:prune-media
    {--days= : Hapus media lebih tua dari sekian hari (bawaan BACKUP_MEDIA_KEEP_DAYS, 90)}
    {--tenant=* : Hanya agensi ini (id atau slug); bisa diulang}
    {--mode= : archive (pindah ke luar server dulu) atau delete}
    {--apply : Benar-benar menjalankan; tanpa ini hanya uji kering}
    {--force : Tanpa konfirmasi di produksi}')]
#[Description('Arsipkan atau hapus media WhatsApp yang lebih tua dari N hari (uji kering kecuali --apply)')]
class BackupPruneMediaCommand extends Command
{
    public function handle(Shell $shell): int
    {
        $days = (int) ($this->option('days') ?? config('jitu.backup.media.keep_days', 90));
        $mode = (string) ($this->option('mode') ?? config('jitu.backup.media.mode', 'archive'));

        if ($days < 1 || ! in_array($mode, ['archive', 'delete'], true)) {
            $this->error('--days minimal 1 dan --mode harus archive atau delete.');

            return self::FAILURE;
        }

        $tenants = $this->tenants();
        if ($tenants === null) {
            return self::FAILURE;
        }

        $disk = Storage::disk('local');
        $cutoff = now()->subDays($days)->getTimestamp();
        $files = [];

        foreach ($tenants as $id) {
            foreach ($disk->allFiles("wa-media/$id") as $path) {
                if (($at = $disk->lastModified($path)) < $cutoff) {
                    $files[$path] = ['tenant' => $id, 'bytes' => $disk->size($path), 'at' => $at];
                }
            }
        }

        $apply = (bool) $this->option('apply');
        $log = storage_path('logs/media-prune-'.now()->format('Ymd-His').($apply ? "-$mode" : '-uji-kering').'.log');
        @file_put_contents($log, collect($files)->map(fn ($f, $p) => $p."\t".$f['bytes']."\t".date('Y-m-d H:i', $f['at']))->implode("\n").($files ? "\n" : ''));

        $this->line("Media lebih tua dari $days hari (sebelum ".date('d M Y', $cutoff).'), mode '.$mode.':');
        foreach (collect($files)->groupBy('tenant') as $tenant => $group) {
            $this->line(sprintf('  agensi %s: %d berkas, %s MB', $tenant, $group->count(), number_format($group->sum('bytes') / 1048576, 1, ',', '.')));
        }
        $total = number_format(collect($files)->sum('bytes') / 1048576, 1, ',', '.');
        $this->line('  total: '.count($files)." berkas, $total MB. Daftar: $log");

        if ($files === []) {
            return self::SUCCESS;
        }

        if (! $apply) {
            $this->info('Uji kering: tidak ada yang diubah. Tambahkan --apply untuk menjalankan.');

            return self::SUCCESS;
        }

        if (app()->isProduction() && ! $this->option('force') && ! $this->confirm('Yakin '.($mode === 'archive' ? 'mengarsipkan' : 'MENGHAPUS').' '.count($files).' berkas media ini?')) {
            $this->warn('Dibatalkan.');

            return self::FAILURE;
        }

        if ($mode === 'archive' && ! $this->archive($shell, array_keys($files))) {
            return self::FAILURE;
        }

        if ($mode === 'delete') {
            $disk->delete(array_keys($files));
        }

        $marked = $this->mark(array_keys($files), $mode);
        Log::warning('backup:prune-media', ['mode' => $mode, 'days' => $days, 'files' => count($files), 'bytes' => collect($files)->sum('bytes'), 'messages' => $marked, 'list' => $log]);
        $this->info(($mode === 'archive' ? 'Diarsipkan' : 'Dihapus').': '.count($files)." berkas ($total MB), $marked pesan ditandai.");

        return self::SUCCESS;
    }

    /** @return list<int>|null tenant ids to look at, or null (reported) when one given is unknown */
    private function tenants(): ?array
    {
        $given = (array) $this->option('tenant');

        if ($given === []) {
            return collect(Storage::disk('local')->directories('wa-media'))->map(fn ($d) => basename($d))
                ->filter(fn ($id) => ctype_digit($id))->map(fn ($id) => (int) $id)->values()->all();
        }

        $ids = [];
        foreach ($given as $key) {
            $tenant = Tenant::query()->where(ctype_digit((string) $key) ? 'id' : 'slug', $key)->first();
            if ($tenant === null) {
                $this->error("Agensi tidak ditemukan: $key");

                return null;
            }
            $ids[] = (int) $tenant->getKey();
        }

        return $ids;
    }

    /** @param  list<string>  $paths */
    private function archive(Shell $shell, array $paths): bool
    {
        $remote = rtrim(trim((string) config('jitu.backup.rclone_remote')), '/');

        if ($remote === '' || str_starts_with($remote, '-') || ! str_contains($remote, ':') || ! $shell->exists('rclone')) {
            $this->error('Mode archive butuh BACKUP_RCLONE_REMOTE dan rclone. Tidak ada yang diubah.');

            return false;
        }

        $list = tempnam(sys_get_temp_dir(), 'media-prune-');
        file_put_contents($list, implode("\n", $paths)."\n");

        try {
            [$code, $err] = $shell->run(['rclone', 'move', '--files-from-raw', $list, '--', Storage::disk('local')->path(''), "$remote/media-archive"],
                config('jitu.backup.rclone_env'), null, 4 * 3600);
        } finally {
            @unlink($list);
        }

        if ($code !== 0) {
            $this->error('Pemindahan ke luar server gagal (kode '.$code.'). Berkas yang belum pindah tetap di server.');
            Log::warning('backup:prune-media archive failed', ['code' => $code, 'error' => mb_substr($err, 0, 300)]);
        }

        return $code === 0;
    }

    /** The chat shows "diarsipkan" or "sudah dihapus" for these messages; the old path is kept to restore by hand. */
    private function mark(array $paths, string $mode): int
    {
        $marked = 0;

        foreach (array_chunk($paths, 500) as $chunk) {
            WaMessage::query()->withoutGlobalScopes()->whereIn('media->path', $chunk)->each(function (WaMessage $m) use ($mode, &$marked) {
                $m->forceFill(['media' => Arr::except((array) $m->media, ['path', 'data']) + [
                    'error' => $mode === 'archive' ? 'archived' : 'expired', 'pruned_path' => $m->media['path'], 'pruned_at' => now()->toIso8601String(),
                ]])->saveQuietly();
                $marked++;
            });
        }

        return $marked;
    }
}
