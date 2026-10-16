<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A promo email series from the platform owner. Not tenant-scoped. */
class EmailCampaign extends Model
{
    public const DRAFT = 'draft';

    public const RUNNING = 'running';

    public const PAUSED = 'paused';

    public const STOPPED = 'stopped';

    public const FINISHED = 'finished';

    public const SEGMENTS = [
        'trial_active' => 'Masa percobaan aktif',
        'trial_expired' => 'Percobaan berakhir tanpa bayar',
        'subscribers' => 'Pelanggan aktif',
        'all' => 'Semua',
    ];

    public const STATUSES = [
        self::DRAFT => 'Draf',
        self::RUNNING => 'Berjalan',
        self::PAUSED => 'Dijeda',
        self::STOPPED => 'Dihentikan',
        self::FINISHED => 'Selesai',
    ];

    public const MAX_STEPS = 5;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'starts_at' => 'datetime',
            'audience_frozen_at' => 'datetime',
            'stop_when_subscribed' => 'boolean',
        ];
    }

    public function sends(): HasMany
    {
        return $this->hasMany(EmailCampaignSend::class);
    }

    public function isTrialSegment(): bool
    {
        return in_array($this->segment, ['trial_active', 'trial_expired'], true);
    }

    /** @return list<array{subject: string, body: string, button: ?string, url: ?string}> */
    public function emails(): array
    {
        return array_values(array_slice((array) $this->steps, 0, self::MAX_STEPS));
    }

    /** @return array{sent: int, failed: int, unsubscribed: int, waiting: int} */
    public function stats(): array
    {
        $counts = $this->sends()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return [
            'sent' => (int) ($counts[EmailCampaignSend::SENT] ?? 0),
            'failed' => (int) ($counts[EmailCampaignSend::FAILED] ?? 0),
            'unsubscribed' => (int) $this->sends()->whereNotNull('unsubscribed_at')->distinct()->count('user_id'),
            'waiting' => (int) ($counts[EmailCampaignSend::PENDING] ?? 0) + (int) ($counts[EmailCampaignSend::QUEUED] ?? 0),
        ];
    }
}
