<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One backup run, with what happened at each step: kind 'full' (backup:run, nightly) or 'database' (backup:database,
 * every few hours). database_file is set when the run's dump passed `gzip -t`. Platform-wide, not tenant-owned.
 */
class BackupRun extends Model
{
    public const RUNNING = 'running';

    public const OK = 'ok';

    public const WARNING = 'warning';

    public const FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'steps' => 'array'];
    }

    /** The last finished nightly run (backup:run). */
    public static function latestRun(): ?self
    {
        return static::query()->where('kind', 'full')->where('status', '!=', self::RUNNING)->latest('started_at')->first();
    }

    /** The last run whose database dump passed `gzip -t`, from either kind of run. */
    public static function latestValidDatabase(): ?self
    {
        return static::query()->whereNotNull('database_file')->latest('finished_at')->first();
    }

    public function label(): string
    {
        return ['running' => 'Berjalan', 'ok' => 'Berhasil', 'warning' => 'Berhasil dengan peringatan', 'failed' => 'Gagal'][$this->status] ?? $this->status;
    }
}
