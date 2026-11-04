<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One report to one person (App\Reports\DailyReportSender): pending until due_at, then sent on WhatsApp, sent by
 * email instead, or failed. Unique per agency, person, kind and day, except "sample" (Kirim contoh sekarang).
 * Read across agencies by the scheduler, so it is not tenant-scoped; every screen filters on tenant_id itself.
 */
#[Fillable(['tenant_id', 'user_id', 'kind', 'report_date', 'status', 'due_at', 'period_label', 'error', 'sent_at'])]
class ReportDelivery extends Model
{
    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    public const SAMPLE = 'sample';

    public const PENDING = 'pending';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const EMAIL = 'email';

    public const FAILED = 'failed';

    public const KINDS = [
        self::DAILY => 'Harian',
        self::WEEKLY => 'Mingguan',
        self::MONTHLY => 'Bulanan',
        self::SAMPLE => 'Contoh',
    ];

    public const STATUSES = [
        self::PENDING => 'Menunggu',
        self::SENDING => 'Sedang dikirim',
        self::SENT => 'Terkirim lewat WhatsApp',
        self::EMAIL => 'Terkirim lewat email',
        self::FAILED => 'Gagal',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'due_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
