<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One run of the daily backup, with what happened at each step. Platform-wide, not tenant-owned. */
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

    public static function latestRun(): ?self
    {
        return static::query()->where('status', '!=', self::RUNNING)->latest('started_at')->first();
    }

    public function label(): string
    {
        return ['running' => 'Berjalan', 'ok' => 'Berhasil', 'warning' => 'Berhasil dengan peringatan', 'failed' => 'Gagal'][$this->status] ?? $this->status;
    }
}
